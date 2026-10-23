<?php

namespace Native\Mobile\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Native\Mobile\Edge\NativeRouter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets `native:watch`'s screen picker reach the app even when no native
 * screen is on show.
 *
 * The fast path for a screen change is the runloop's hot-reload handler, which
 * consumes the intent and re-enters at the chosen screen. But that only exists
 * while a native component is running. Land on a web route — a 404, say, or
 * anything reached by exiting to the WebView — and the reload trigger takes the
 * WebView branch instead: reboot PHP, reload the current URL. No runloop, so
 * nothing reads the intent, and the terminal loses its grip on the app exactly
 * when you most want a way out.
 *
 * That WebView reload is still an ordinary request, though, so catching it here
 * is enough: redirect to the requested screen and `Route::native` takes over,
 * republishing a native tree and reactivating the native renderer.
 *
 * Only registered on device (see NativeServiceProvider), so this costs a normal
 * web app nothing.
 */
class HonorsRequestedNativeScreen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPageLoad($request)) {
            return $next($request);
        }

        $uri = NativeRouter::takeScreenIntent();

        if ($uri === null || $uri === '/'.ltrim($request->path(), '/')) {
            return $next($request);
        }

        NativeRouter::debugLog("screen intent claimed by HTTP request — redirecting to $uri");

        return redirect($uri);
    }

    /**
     * Whether this request is the WebView navigating, rather than fetching
     * something the page referenced.
     *
     * An asset request must never claim the intent: it would consume the one
     * shot we get, redirect a stylesheet to a screen, and leave the app
     * sitting exactly where it was. Assets ask for their own type and only
     * tolerate `*​/*`, so requiring `text/html` outright separates them from a
     * document load.
     */
    private function isPageLoad(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->ajax()
            && str_contains((string) $request->header('Accept'), 'text/html');
    }
}
