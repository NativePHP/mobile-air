<?php

namespace Native\Mobile\Edge\Asgard;

use Closure;
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
 * Until the first answer lands, it shows a placeholder skeleton and a
 * plain nav bar, with a back button when it is not the root. The bar's
 * title is the route's title template with the route parameters filled
 * in, or titlePlaceholder() when the route has none. It never borrows the
 * title of another screen. The chrome in each answer then replaces it.
 * When that chrome has a nav bar with no title of its own, the template
 * fills in again; the placeholder never comes back once the body landed.
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
 * skeleton, the timeout or the placeholder title, by naming it in
 * `nativephp.asgard.screen`.
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

    /** The longest route title template, and the longest title shown from one, in characters. */
    public const TITLE_MAX = 100;

    /** A `{name}` placeholder in a route title template. */
    public const TITLE_PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /** Set by titlePlaceholderUsing(), for every server screen class. */
    private static ?Closure $titlePlaceholderUsing = null;

    private string $asgardScreen = '';

    /** The route's title template, as the server listed it. */
    private ?string $asgardTitle = null;

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

    /**
     * Point this screen at a server screen address, with the route's title
     * template if it has one. Called by the router.
     */
    public function showScreen(string $address, ?string $title = null): void
    {
        if (preg_match(self::ADDRESS_PATTERN, $address) !== 1) {
            throw new \InvalidArgumentException("[{$address}] is not an Asgard screen address.");
        }

        $this->asgardScreen = $address;
        $this->asgardTitle = $title !== null && self::validTitle($title) ? $title : null;
    }

    /**
     * Whether a route's title template can be shown: a string of valid
     * UTF-8, not blank, at most TITLE_MAX characters, and free of control
     * characters. It is shown as plain text, never markup.
     */
    public static function validTitle(mixed $title): bool
    {
        return is_string($title)
            && mb_check_encoding($title, 'UTF-8')
            && trim($title) !== ''
            && mb_strlen($title) <= self::TITLE_MAX
            && preg_match('/\p{Cc}/u', $title) === 0;
    }

    /**
     * The route's title template with its `{name}` placeholders filled from
     * the route parameters, or null when the route has no template.
     *
     * A placeholder with no parameter of that name, such as an optional
     * parameter left out, is removed. Only route parameters are filled in,
     * never the query string. Control characters in a value become spaces,
     * and the result is cut to TITLE_MAX characters. A title that comes out
     * blank counts as none.
     */
    public function routeTitle(): ?string
    {
        if ($this->asgardTitle === null) {
            return null;
        }

        $params = $this->nativeParams;

        $title = preg_replace_callback(self::TITLE_PLACEHOLDER, static function (array $match) use ($params): string {
            $value = $params[$match[1]] ?? null;

            return is_string($value) || is_int($value) || is_float($value) ? mb_scrub((string) $value, 'UTF-8') : '';
        }, $this->asgardTitle);

        $title = trim(mb_substr((string) preg_replace('/\p{Cc}+/u', ' ', (string) $title), 0, self::TITLE_MAX));

        return $title === '' ? null : $title;
    }

    /**
     * Set the title every server screen without a route title shows until
     * its body lands. The callback gets the screen's URI and returns the
     * title. Null goes back to the default.
     *
     *     ServerScreen::titlePlaceholderUsing(fn (string $uri) => __('Loading…'));
     */
    public static function titlePlaceholderUsing(?Closure $callback): void
    {
        self::$titlePlaceholderUsing = $callback;
    }

    /**
     * The title a screen without a route title shows until its body lands:
     * the titlePlaceholderUsing() callback's, or `__('Loading…')`, so an
     * app's translation files cover it. Override it on a subclass to set it
     * per app.
     */
    public function titlePlaceholder(): string
    {
        if (self::$titlePlaceholderUsing !== null) {
            return (string) (self::$titlePlaceholderUsing)($this->nativeRouter?->currentUri() ?? '');
        }

        return function_exists('__') ? (string) __('Loading…') : 'Loading…';
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

        $this->asgardChrome = $parsed;

        return true;
    }

    /**
     * The chrome in use.
     *
     * Before any chrome has arrived it is a plain nav bar, with a back
     * button when this is not the root. Its title is the route title, or
     * titlePlaceholder() while the body has not landed.
     *
     * Once the server's chrome has arrived it is used as sent, with one
     * addition: a nav bar with no title, title view or logo of its own
     * shows the route title. Chrome with no nav bar gets none. The
     * placeholder is only ever a stand-in for a body still on its way, so
     * no later chrome brings it back.
     */
    public function serverChrome(): array
    {
        $title = $this->routeTitle();

        if ($this->asgardChrome !== null) {
            $chrome = $this->asgardChrome;
            $bar = $chrome['nav_bar'] ?? null;

            if ($title !== null && is_array($bar) && ! isset($bar['title']) && ! isset($bar['title_view']) && ! isset($bar['logo'])) {
                $chrome['nav_bar']['title'] = $title;
            }

            return $chrome;
        }

        $bar = $this->nativeRouter?->isRootScreen() === false ? ['back' => true] : [];
        $title ??= ServerRegion::hasFragment(self::REGION, $this) ? null : $this->titlePlaceholder();

        if ($title !== null && $title !== '') {
            $bar['title'] = $title;
        }

        return $bar === [] ? [] : ['nav_bar' => $bar];
    }

    /** Forget any failure not yet fired, and the titlePlaceholderUsing() callback. For tests. */
    public static function flushChrome(): void
    {
        static::$failed = [];
        self::$titlePlaceholderUsing = null;
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
