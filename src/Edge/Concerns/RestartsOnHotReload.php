<?php

namespace Native\Mobile\Edge\Concerns;

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;

/**
 * Rebooting the PHP runtime when a watched file changes, without losing the
 * developer's place in the app.
 *
 * PHP cannot re-read its own already-included classes, so a mobile hot reload
 * is not a re-render — it is a process restart. The native side posts an
 * EVENT_HOT_RELOAD, this loop writes down where the user is (top-of-stack URI
 * plus the history beneath it), drops every compiled Blade view, and exits;
 * Kotlin / Swift then re-executes PHP from scratch and `Route::native`'s
 * handler replays the stack from the file.
 *
 * All of it is dev-loop machinery specific to a native shell that owns the PHP
 * process, which is why it does not live in core's runloop — core just asks
 * `handlePlatformEvent()` whether anybody wants this event.
 *
 * Reads `EVENT_HOT_RELOAD` off the using class rather than declaring it here:
 * a trait constant is invisible to PHPStan from a SUBCLASS of the using class,
 * so `BenchmarkComponent::EVENT_HOT_RELOAD` — and the same read in any app's
 * own component — would be flagged as undefined. See NativeComponent.
 */
trait RestartsOnHotReload
{
    /**
     * Hot reload: write the restart signal and exit so the native side
     * re-executes with fresh PHP.
     */
    protected function handleHotReloadEvent(array $event): bool
    {
        if (($event['type'] ?? -1) !== self::EVENT_HOT_RELOAD) {
            return false;
        }

        $this->flushCompiledViews();
        ['uri' => $uri, 'stack' => $stack] = $this->hotRestartPayload();
        @file_put_contents(
            storage_path('framework/.hot_restart'),
            json_encode(['uri' => $uri, 'stack' => $stack, 'ts' => time()])
        );
        NativeRouter::debugLog("HOT_RELOAD: wrote restart signal for $uri (stack depth=".count($stack).')');
        $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::RESTART, $uri);
        $this->stop();

        return true;
    }

    /**
     * Where the rebooted runtime should land after a hot reload, plus the
     * history to restore beneath it.
     *
     * Normally that's wherever the user actually IS — the native router's
     * top-of-stack URI, not `request()->path()` (the original HTTP entry
     * point, typically `/`), otherwise every reload dumps them back at the
     * root — with the full stack serialized so the back button survives the
     * reboot. `Route::native`'s handler replays the entries below the top via
     * NativeRouter::preloadStack().
     *
     * A screen change requested from the `native:watch` terminal wins over
     * the live stack: it is asking to GO somewhere, so the chosen screen
     * becomes the new root rather than being pushed onto history the user is
     * no longer in.
     *
     * @return array{uri: string, stack: list<array{uri: string, params: array}>}
     */
    protected function hotRestartPayload(): array
    {
        if ($requested = NativeRouter::takeScreenIntent()) {
            NativeRouter::debugLog("HOT_RELOAD: screen change requested — $requested");

            return ['uri' => $requested, 'stack' => []];
        }

        return [
            'uri' => $this->router()?->currentUri() ?? '/'.ltrim(request()->path(), '/'),
            'stack' => $this->router()?->getStackEntries() ?? [],
        ];
    }

    protected function flushCompiledViews(): void
    {
        $viewPath = storage_path('framework/views');

        if (is_dir($viewPath)) {
            foreach (glob("{$viewPath}/*.php") as $file) {
                // Skip .blade.php source files — these are created by
                // Laravel's createBladeViewFromString() for inline component
                // views (e.g. self-closing components returning ''). Deleting
                // them causes "View [hash] not found" errors.
                if (str_ends_with($file, '.blade.php')) {
                    continue;
                }

                @unlink($file);
            }
        }

        // Critical: clear stat cache AFTER deleting files so PHP sees
        // the deletions. Long-running processes cache stat() results,
        // and Blade's isExpired() uses file_exists() / filemtime().
        clearstatcache();

        // Clear the view finder cache so Blade re-discovers templates
        if (function_exists('app') && app()->bound('view')) {
            app('view')->getFinder()->flush();
        }

        // Reset OPcache if available — the long-running process may
        // have cached bytecode for the old compiled views
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }
}
