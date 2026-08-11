<?php

namespace Native\Mobile\Edge\Concerns;

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Platform;

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

        $this->onBackPressed();

        return true;
    }

    /**
     * Back was pressed at the root of the stack, where there is nothing to
     * pop. Follow platform convention instead of letting a BACK intent empty
     * the router's stack, exit the runloop, and strand the user on the blank
     * WebView underneath: on Android the system back button backgrounds the
     * app; on iOS the press is ignored.
     *
     * The Platform probe is null under `Native::test()` (no bridge), so
     * returning false there keeps the harness's BACK-intent assertions.
     */
    protected function handleBackAtRoot(): bool
    {
        if (($platform = Platform::current()) === null) {
            return false;
        }

        if ($platform === Platform::ANDROID && function_exists('nativephp_call')) {
            nativephp_call('System.MinimizeApp', '{}');
        }

        return true;
    }

    /**
     * Deep link / universal link arriving while the app is already running.
     * The native shell (DeepLinkRouter) posts this to wake the blocked event
     * loop — a warm php:// load can't route because that loop owns the PHP
     * thread. Turn it into a NAVIGATE intent and consume the event;
     * NativeRouter resolves the URI (with route params) and pushes the target
     * screen, exactly like an in-app @tap navigate.
     */
    protected function handlePlatformNativeEvent(string $eventName, mixed $payload): bool
    {
        if ($eventName !== '__deeplink') {
            return false;
        }

        $uri = is_array($payload) ? ($payload['uri'] ?? null) : null;
        if (is_string($uri) && $uri !== '') {
            NativeRouter::debugLog("DEEPLINK: navigating to $uri");
            $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::NAVIGATE, $uri);
            $this->stop();
        }

        return true;
    }

    public function exitToWeb(string $uri): void
    {
        // Screen-level concern — see navigate().
        if ($this->nativeParentComponent !== null) {
            $this->rootScreen()->exitToWeb($uri);

            return;
        }

        $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::EXIT_WEB, $uri);
        $this->stop();
    }
}
