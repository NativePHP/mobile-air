<?php

namespace Native\Mobile\Http;

use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Support\Lane;
use RuntimeException;

/**
 * What a request for a native screen runs: the body of every Route::native()
 * route, and of a server route opened by OpensServerRoutes.
 *
 * It starts the navigation runloop at the requested path, replays the
 * navigation stack a hot reload saved, and answers with whatever the
 * runloop exits with. The screen class comes from the route. For a server
 * route it is Asgard\ServerScreen, and NativeRouter::start() looks up the
 * screen address from the path.
 */
final class NativeScreenEntry
{
    /** @param class-string $componentClass */
    public static function handle(string $componentClass): mixed
    {
        // A lane other than the main one asked for a native screen,
        // most often a link or redirect in a php web view inside a
        // native screen. It must never enter the runloop (see
        // NativeRouter::openOnMainLane()), so the screen on show
        // opens the route instead and the web view keeps its page.
        if (! Lane::isMain()) {
            $path = '/'.ltrim(request()->path(), '/');
            $queryString = (string) request()->server('QUERY_STRING', '');
            $target = $queryString === '' ? $path : "$path?$queryString";

            if (! NativeRouter::openOnMainLane($target)) {
                throw new RuntimeException("Native screen [{$target}] can only run on the main lane, and native did not take the request to open it there.");
            }

            return response()->noContent();
        }

        // Native route reached without a native runtime — a shared
        // app link opened in a plain browser, a crawler, a
        // misconfigured deploy. The runloop can never satisfy these
        // (no device is attached to the request), so if the app
        // bound a fallback, let it answer (landing page, app-store
        // redirect). Unbound, everything below is unchanged.
        if (! env('NATIVEPHP_RUNNING') && ! config('nativephp-internal.running')
            && app()->bound(NativeRouteFallback::class)) {
            return app(NativeRouteFallback::class)->handle($componentClass);
        }

        // HTTP feature tests ($this->get('/')) must never enter the
        // runloop: it blocks in wait_event against the REAL bridge —
        // with a live Jump session that's ~90s of reconnect spinning
        // per request. Answer 200 so route-level smoke tests pass,
        // and point at the component harness for actual coverage.
        if (app()->runningUnitTests()) {
            return response(
                "Native screen [{$componentClass}] — test it with Native::test() / Native::visit().",
                200
            );
        }

        $router = new NativeRouter;
        $path = '/'.ltrim(request()->path(), '/');
        $resolved = NativeRouter::resolve($path);
        $params = $resolved ? $resolved['params'] : [];

        // Hot-reload stack restoration. PHP wrote the full
        // navigation stack to `.hot_restart` when the user
        // saved a file; replay the entries below the current
        // top so back-button history survives the PHP reboot.
        // We delete the file here (PHP becomes the sole
        // consumer); iOS / Android just peek at it for the
        // top URI to dispatch.
        $restartPath = storage_path('framework/.hot_restart');
        if (is_file($restartPath)) {
            $raw = @file_get_contents($restartPath);
            $data = $raw ? @json_decode($raw, true) : null;
            $age = time() - (int) ($data['ts'] ?? 0);

            // The hot-reload re-exec lands on the WebView's URL (almost
            // always "/"), but the user may have navigated deeper before
            // saving. Redirect to the saved top screen so ITS route
            // restores onto it — otherwise the entry screen ("/") gets
            // pushed on top of the restored stack and the user is dumped
            // back at root. Leave .hot_restart in place; the target
            // route is the sole consumer. Compare paths alone so we can't
            // redirect-loop: the saved top keeps its query string and
            // $path never has one.
            $savedTop = is_array($data) ? ($data['uri'] ?? null) : null;
            $savedTopNorm = $savedTop !== null ? '/'.ltrim($savedTop, '/') : null;
            if ($age <= 30 && $savedTopNorm && NativeRouter::pathOf($savedTopNorm) !== $path) {
                return redirect($savedTopNorm);
            }

            @unlink($restartPath);
            $stack = is_array($data['stack'] ?? null) ? $data['stack'] : [];
            // Drop the entry matching the current request URI —
            // `start()` will push that as the top itself.
            if ($age <= 30 && ! empty($stack)) {
                $last = end($stack);
                if (is_array($last) && is_string($last['uri'] ?? null)
                    && NativeRouter::pathOf($last['uri']) === $path) {
                    array_pop($stack);
                }
                if (! empty($stack)) {
                    $router->preloadStack($stack);
                }
            }
        }

        NativeRouter::logRestartAfterCrash();

        // Hand the launch screen its query the way a warm link does: in
        // the URI, parsed by the router. request()->query() has been
        // through TrimStrings and ConvertEmptyStringsToNull by now.
        $queryString = (string) request()->server('QUERY_STRING', '');
        $exitUri = $router->start($componentClass, $params, $queryString === '' ? $path : "$path?$queryString");

        if ($exitUri !== null) {
            return redirect($exitUri);
        }

        // Hot reload exit — return 204 so the WebView doesn't load stale content
        if (file_exists(storage_path('framework/.hot_restart'))) {
            return response()->noContent();
        }

        return '';
    }
}
