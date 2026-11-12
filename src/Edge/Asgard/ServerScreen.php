<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeRouter;

/**
 * The screen every server route opens (docs/ASGARD-PROTOCOL.md §12,
 * "Screens").
 *
 * One class stands in for every server screen. The router tells it which
 * one with showScreen(), from the route the server listed. Its body is a
 * single region named `screen`, so a server screen is fetched, painted,
 * pushed to and tapped exactly as any region is: through the bound
 * FragmentSource, ServerRegion and the fragment callback registry.
 *
 * Its address is the server screen's, not this class's, and its state is
 * the route's parameters and query string. It has no public properties of
 * its own and no #[NativeAction] methods, so a client target in a server
 * screen never runs anything.
 *
 * Until the first answer lands, it shows a placeholder skeleton and the
 * chrome this screen had last time it was shown in this process, or a
 * plain nav bar with a back button. The chrome in each answer then
 * replaces it. Offline, that placeholder is all the screen is, as for a
 * region.
 */
class ServerScreen extends NativeComponent implements Contracts\AddressedComponent
{
    /** The region the body travels under. */
    public const REGION = 'screen';

    /** The chrome's search field calls this on the screen; it sends the server's on_query. */
    public const SEARCH_METHOD = 'asgardSearchQuery';

    /** Route patterns the server may list: literal and {param} segments, {param?} last. */
    public const URI_PATTERN = '#^/$|^(/([A-Za-z0-9._~-]+|\{[A-Za-z_][A-Za-z0-9_]*\}))*(/([A-Za-z0-9._~-]+|\{[A-Za-z_][A-Za-z0-9_]*\??\}))$#D';

    public const ADDRESS_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/D';

    /**
     * The last chrome each screen address showed, so a screen opened again
     * starts in its own chrome rather than the plain default.
     *
     * @var array<string, array>
     */
    protected static array $lastChrome = [];

    private string $asgardScreen = '';

    private ?array $asgardChrome = null;

    /** Point this screen at a server screen address. Called by the router. */
    public function showScreen(string $address): void
    {
        if (preg_match(self::ADDRESS_PATTERN, $address) !== 1) {
            throw new \InvalidArgumentException("[{$address}] is not an Asgard screen address.");
        }

        $this->asgardScreen = $address;
        $this->asgardChrome = static::$lastChrome[$address] ?? null;
    }

    public function asgardAddress(): string
    {
        return $this->asgardScreen;
    }

    /** Route parameters over the query string, as a server screen's state. */
    public function asgardState(): array
    {
        return array_merge($this->nativeQuery, $this->nativeParams);
    }

    /**
     * Take the chrome from a server answer. Checked first; chrome that fails
     * leaves the screen on the chrome it had, and throws with `app.debug` on.
     */
    public function receiveChrome(array $chrome): bool
    {
        $parsed = ServerChrome::parse($chrome, $reason);

        if ($parsed === null) {
            $message = "Asgard chrome for screen [{$this->asgardScreen}] cannot be used: {$reason}";

            if (config('app.debug')) {
                throw new \RuntimeException($message);
            }

            NativeRouter::debugLog($message);

            return false;
        }

        $this->asgardChrome = static::$lastChrome[$this->asgardScreen] = $parsed;

        return true;
    }

    /** The chrome in use: the server's, or the default while there is none. */
    public function serverChrome(): array
    {
        if ($this->asgardChrome !== null) {
            return $this->asgardChrome;
        }

        return $this->nativeRouter?->isRootScreen() === false ? ['nav_bar' => ['back' => true]] : [];
    }

    /** Forget every remembered chrome. For tests. */
    public static function flushChrome(): void
    {
        static::$lastChrome = [];
    }

    public function render(): Element
    {
        NativeElementCollector::reset();
        NativeElementCollector::setCallbacks($this->nativeCallbacks);
        NativeElementCollector::setOwner($this);

        if (ServerRegion::open(self::REGION)) {
            $this->paintSkeleton();
        }

        ServerRegion::close();

        // Paint the chrome's content slots while the collector is bound to
        // this screen, then pin each to the region's registry.
        $chrome = $this->serverChrome();
        $this->chromeContent = [];

        foreach (array_intersect_key($chrome, ['bottom_bar' => true, 'tab_accessory' => true]) + array_filter(['title_view' => $chrome['nav_bar']['title_view'] ?? null]) as $slot => $nodes) {
            $this->chromeContent[$slot] = NativeElementCollector::capture(fn () => ServerRegion::paint($nodes))
                ->ownCallbacks(ServerRegion::registryForChrome(self::REGION, $this));
        }

        return $this->wrapWithChrome(NativeElementCollector::collect());
    }

    /** @var array<string, Element> */
    private array $chromeContent = [];

    protected function chromeLayout(): ?NativeLayout
    {
        $chrome = $this->serverChrome();

        if (! isset($chrome['nav_bar']) && ! isset($chrome['tab_bar']) && $this->chromeContent === []) {
            return null;
        }

        return new ServerLayout($chrome, ServerRegion::registryForChrome(self::REGION, $this), $this->chromeContent);
    }

    public function navigationOptions(): ?NavBarOptions
    {
        $hidden = $this->serverChrome()['nav_bar']['hidden'] ?? null;

        return $hidden === null ? null : NavBarOptions::make()->hidden($hidden);
    }

    public function tabBarOptions(): ?TabBarOptions
    {
        $bar = $this->serverChrome()['tab_bar'] ?? [];

        if (! isset($bar['hidden']) && ! isset($bar['highlight'])) {
            return null;
        }

        $options = TabBarOptions::make();

        if (isset($bar['hidden'])) {
            $options->hidden($bar['hidden']);
        }
        if (isset($bar['highlight'])) {
            $options->highlight($bar['highlight']);
        }

        return $options;
    }

    /**
     * The nav bar's search field. Sends the chrome's on_query to the server
     * with the query appended, as an on_change does.
     */
    public function asgardSearchQuery(string $query): void
    {
        $slot = $this->serverChrome()['nav_bar']['search']['on_query'] ?? null;

        if ($slot === null || $slot['target'] !== FragmentCallbacks::TARGET_SERVER) {
            return;
        }

        ServerRegion::action($this, self::REGION, $slot['call'], [...$slot['args'], $query]);
    }

    /** A plain skeleton: a title bar and a few lines. */
    protected function paintSkeleton(): void
    {
        NativeElementCollector::open('column', ['class' => 'w-full p-4 gap-3']);
        NativeElementCollector::leaf('column', ['class' => 'h-6 w-2/3 rounded-md bg-gray-200 dark:bg-gray-800']);

        foreach (['w-full', 'w-full', 'w-5/6', 'w-1/2'] as $width) {
            NativeElementCollector::leaf('column', ['class' => "h-4 {$width} rounded-md bg-gray-100 dark:bg-gray-900"]);
        }

        NativeElementCollector::close();
    }
}
