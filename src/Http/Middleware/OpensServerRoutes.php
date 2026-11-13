<?php

namespace Native\Mobile\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Native\Mobile\Edge\Asgard\ServerScreen;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Http\NativeScreenEntry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a server route (docs/ASGARD-PROTOCOL.md §12, "Routes") that came in
 * as a request.
 *
 * Server routes live only in NativeRouter, filled at boot from the cached
 * route list and later from the server. Navigation inside the app finds
 * them there. A request does not: a cold deep link the shell loads, or the
 * hot-reload restore that redirects to the screen that was on top, goes
 * through Laravel's router, which has no route for them and answers 404.
 *
 * So when no Laravel route matches the path and a server route does, this
 * runs the same entry a Route::native() route runs, with the generic
 * ServerScreen. Laravel routes, including every Route::native() route,
 * keep priority. A Route::fallback() does not count as a match, since it
 * is the app's 404 page, not a route for this path.
 *
 * Only registered on device (see NativeServiceProvider).
 */
class OpensServerRoutes
{
    public function __construct(protected Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isScreenLoad($request)) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');

        if ($this->laravelHasRoute($request) || ($screen = NativeRouter::serverScreenFor($path)) === null) {
            return $next($request);
        }

        NativeRouter::debugLog("server route {$path} opens {$screen}");

        return $this->router->prepareResponse($request, NativeScreenEntry::handle(ServerScreen::screenClass()));
    }

    /**
     * A screen is opened by the WebView loading a page (Accept: text/html)
     * or by the shell dispatching a route straight into the runtime, which
     * sends no Accept header. An asset or an XHR is neither.
     */
    private function isScreenLoad(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax()) {
            return false;
        }

        $accept = (string) $request->header('Accept');

        return $accept === '' || str_contains($accept, 'text/html');
    }

    /**
     * Deliberately not RouteCollection::match(), which binds the request to
     * the route it finds (see NativeComponent::routableDeepLink()).
     */
    private function laravelHasRoute(Request $request): bool
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! $route->isFallback && in_array('GET', $route->methods(), true) && $route->matches($request)) {
                return true;
            }
        }

        return false;
    }
}
