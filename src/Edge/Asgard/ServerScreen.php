<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Asgard\ServerScreenFailed;

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
 * replaces it.
 *
 * It does not wait for ever. When no body has arrived within
 * screenTimeout() of the first frame, or the request for it fails outright
 * (no network, a server error, an answer that fails verification or cannot
 * be rendered), the screen gives up. It goes back to the screen below, or
 * replaces itself with the app's `/` route when there is none, and
 * ServerScreenFailed fires once that screen is the live one. Once a body
 * has landed the timer is off for good: a later answer that fails leaves
 * the body on screen.
 *
 * An app can use its own subclass for every server route, to change the
 * skeleton or the timeout, by naming it in `nativephp.asgard.screen`.
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

    /** Set once the wait for the body has started. */
    private bool $asgardWaited = false;

    /** Set once the screen has given up on its body. */
    private bool $asgardGaveUp = false;

    /**
     * ServerScreenFailed events for screens that gave up, fired by the
     * router once the screen they went to is the live one.
     *
     * @var list<ServerScreenFailed>
     */
    protected static array $failed = [];

    /**
     * The class server routes open: `nativephp.asgard.screen` when it names a
     * subclass of this one, or this class.
     *
     * @return class-string<ServerScreen>
     */
    public static function screenClass(): string
    {
        $class = function_exists('config') ? config('nativephp.asgard.screen') : null;

        if ($class === null || $class === '' || $class === self::class) {
            return self::class;
        }

        if (! is_string($class) || ! is_subclass_of($class, self::class)) {
            NativeRouter::debugLog('Asgard: nativephp.asgard.screen is not a subclass of '.self::class.', so it is not used.');

            return self::class;
        }

        return $class;
    }

    /**
     * Seconds to wait for the body before giving up. 0 or less waits for
     * ever, though a hard failure still gives up at once. Override it on a
     * subclass to set it per app.
     */
    public function screenTimeout(): float
    {
        return (float) config('nativephp.asgard.screen_timeout', 15);
    }

    /**
     * The ServerScreenFailed events waiting to be fired, oldest first, and
     * forget them. The router calls this once the screen a failed server
     * screen went to is the live one.
     *
     * @return list<ServerScreenFailed>
     */
    public static function takeFailures(): array
    {
        $failed = static::$failed;
        static::$failed = [];

        return $failed;
    }

    /**
     * Give up on the body: leave, and have ServerScreenFailed fired on the
     * screen this lands on. Called by ServerRegion::expire() when the timer
     * runs out or a request for the body failed.
     */
    protected function giveUp(string $reason, ?int $status = null, ?string $message = null): void
    {
        if ($this->asgardGaveUp) {
            return;
        }

        $this->asgardGaveUp = true;

        NativeRouter::debugLog("Asgard: server screen [{$this->asgardScreen}] gave up ({$reason}).");

        static::$failed[] = new ServerScreenFailed(
            uri: $this->nativeRouter?->currentUri() ?? '',
            address: $this->asgardScreen,
            reason: $reason,
            status: $status,
            message: $message,
        );

        if (($this->nativeRouter?->stackDepth() ?? 0) > 1) {
            $this->back();
        } else {
            $this->replace('/');
        }

        // After the intent, whose last frame must not ask again: an answer
        // still in flight is dropped from here on.
        ServerRegion::forget($this);
    }

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

    /** Forget every remembered chrome, and any failure not yet fired. For tests. */
    public static function flushChrome(): void
    {
        static::$lastChrome = [];
        static::$failed = [];
    }

    public function render(): Element
    {
        NativeElementCollector::reset();
        NativeElementCollector::setCallbacks($this->nativeCallbacks);
        NativeElementCollector::setOwner($this);

        if (ServerRegion::open(self::REGION)) {
            $this->paintSkeleton();

            // The timer starts with the first frame, which follows mount(),
            // and only ever once.
            if (! $this->asgardWaited) {
                $this->asgardWaited = true;
                ServerRegion::awaitBody($this, $this->screenTimeout());
            }
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
