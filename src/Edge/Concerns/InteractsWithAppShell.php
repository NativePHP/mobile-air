<?php

namespace Native\Mobile\Edge\Concerns;

use Illuminate\Http\Request;
use Native\Mobile\Browser;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Facades\System as SystemFacade;

/**
 * The conversation between a screen and the native app shell wrapped around
 * it: the hardware back button, app teardown, a deep link arriving warm, and
 * the two ways out of the native stack (background the app, or hand the URL
 * back to the WebView).
 *
 * Every one of these is a mobile fact. There is no hardware back button on a
 * desktop; a window closing is not an Activity being destroyed while the
 * process lives on; and "exit to web" only means something when the native
 * shell is hosting a WebView the app can fall back into. Core's runloop asks
 * `handlePlatformEvent()` and `handlePlatformNativeEvent()` whether anyone
 * owns an event, and this is what answers on a phone.
 *
 * Reads `EVENT_SHUTDOWN` off the using class rather than declaring it here —
 * see the note on RestartsOnHotReload for why a trait is the wrong home for a
 * constant an app's own component may read.
 */
trait InteractsWithAppShell
{
    /**
     * App teardown: exit the loop (no hot-restart state, no navigation
     * intent) so the persistent runtime can shut down or park.
     */
    protected function handleShutdownEvent(array $event): bool
    {
        if (($event['type'] ?? -1) !== self::EVENT_SHUTDOWN) {
            return false;
        }

        NativeRouter::debugLog('SHUTDOWN event received — exiting runloop in '.static::class);
        $this->stop();

        return true;
    }

    /** System back button (type 8). */
    protected function handleBackButtonEvent(array $event): bool
    {
        if (($event['type'] ?? -1) !== 8) {
            return false;
        }

        if ($this->nativeHasError) {
            // Dismiss error/dump screen and re-render the component
            $this->clearOverlayState();

            return true;
        }

        $this->runGuardedInteraction('onBackPressed()', function () {
            $this->handleSystemBack();
            $this->flushDispatchedEvents();
        });

        return true;
    }

    /**
     * Back was pressed at the root of the stack, where there is nothing to
     * pop. Follow platform convention instead of letting a BACK intent empty
     * the router's stack, exit the runloop, and strand the user on the blank
     * WebView underneath: on Android the system back button backgrounds the
     * app; on iOS the press is ignored.
     *
     * The platform is null under `Native::test()` (no device), so
     * returning false there keeps the harness's BACK-intent assertions.
     */
    protected function handleBackAtRoot(): bool
    {
        if (($platform = SystemFacade::platform()) === null) {
            return false;
        }

        if ($platform === 'android' && function_exists('nativephp_call')) {
            nativephp_call('System.MinimizeApp', '{}');
        }

        return true;
    }

    /**
     * Give a deep link back to the browser.
     *
     * Android goes through the core System.OpenInBrowser. A plain
     * Browser::open() there resolves the app's own verified App Link straight
     * back to the app, which delivers the link here again, in a loop that never
     * reaches the browser. iOS sends an app's own universal link to Safari when
     * the app opens it, so there the browser plugin's open() is enough. Android
     * projects installed before System.OpenInBrowser existed take that path
     * too, and keep the old behaviour until the native project is reinstalled.
     */
    protected static function openDeepLinkInBrowser(string $url): bool
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('System.OpenInBrowser', json_encode(['url' => $url]));

            // Null means the platform doesn't have it, not that it failed.
            if ($result !== null) {
                return (json_decode($result, true)['success'] ?? false) === true;
            }
        }

        // A no-op when the app doesn't ship the browser plugin.
        return (new Browser)->open($url);
    }

    /**
     * Deep link / universal link arriving while the app is already running.
     * The native shell (DeepLinkRouter) posts this to wake the blocked event
     * loop — a warm php:// load can't route because that loop owns the PHP
     * thread. Turn it into a NAVIGATE intent and consume the event;
     * NativeRouter resolves the URI (with route params) and pushes the target
     * screen, exactly like an in-app @tap navigate.
     */
    protected function handleDeepLinkEvent(string $eventName, mixed $payload): bool
    {
        if ($eventName !== '__deeplink') {
            return false;
        }

        $uri = is_array($payload) ? ($payload['uri'] ?? null) : null;
        $url = is_array($payload) ? ($payload['url'] ?? null) : null;

        if (is_string($uri) && $uri !== '') {
            // A verified app link the app has no route for. Navigating would
            // tear the native UI down and hand the path to the WebView, which
            // serves a local 404 — a dead end the user can't back out of. The
            // link belongs to the site we took it from, so give it back to the
            // browser and leave the current screen alone.
            // Only swallow the link if it was actually handed off — otherwise
            // fall through and behave exactly as before.
            if (is_string($url) && $url !== '' && ! static::routableDeepLink($uri)) {
                NativeRouter::debugLog("DEEPLINK: no route for $uri — opening $url in the browser");

                if (static::openDeepLinkInBrowser($url)) {
                    return true;
                }

                NativeRouter::debugLog('DEEPLINK: browser handoff unavailable, navigating anyway');
            }

            NativeRouter::debugLog("DEEPLINK: navigating to $uri");
            $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::NAVIGATE, $uri);
            $this->stop();
        }

        return true;
    }

    /**
     * Can this app render the target of a deep link?
     *
     * True for a Route::native screen, and also for a plain Laravel route —
     * those legitimately exit to the WebView and render there, which is how
     * WebView and hybrid apps have always handled deep links. Only a URI that
     * matches neither is a guaranteed 404.
     */
    protected static function routableDeepLink(string $uri): bool
    {
        if (NativeRouter::isNativeRoute($uri)) {
            return true;
        }

        // Deliberately not RouteCollection::match() — that binds the request to
        // the matched Route object the collection holds, mutating shared state
        // from what is only meant to be a question.
        $request = Request::create($uri, 'GET');

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && $route->matches($request)) {
                return true;
            }
        }

        return false;
    }

    public function exitToWeb(string $uri): void
    {
        if ($this->openedExternally($uri)) {
            return;
        }

        // Screen-level concern — see navigate().
        if ($this->nativeParentComponent !== null) {
            $this->rootScreen()->exitToWeb($uri);

            return;
        }

        $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::EXIT_WEB, static::appPath($uri));
        $this->stop();
    }

    /**
     * Hand an external URL to the system browser and keep the current screen.
     * Exiting to the WebView can't show it: the shells load only the path of
     * an exit URL, so the native UI would be torn down for a blank page — the
     * same dead end deep links avoid by opening the browser. Browser::open()
     * needs the nativephp/mobile-browser plugin; without it the tap is a
     * no-op rather than a broken screen.
     */
    protected function openedExternally(string $uri): bool
    {
        if (! static::isExternalUrl($uri)) {
            return false;
        }

        if (! (new Browser)->open($uri)) {
            NativeRouter::debugLog("NAVIGATE: no browser handler for $uri — is nativephp/mobile-browser installed?");
        }

        return true;
    }
}
