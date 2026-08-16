<?php

namespace Native\Mobile\Edge;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Runs a native route's middleware before its screen mounts.
 *
 * `Route::native()` returns a real Laravel route, so `->middleware('auth')`
 * — and any group middleware wrapping it — binds exactly as it would on a
 * web route. The catch is that only the FIRST screen arrives as an HTTP
 * request; every screen reached by in-app navigation is mounted straight
 * from the runloop, with no request and no kernel to run a pipeline. That
 * used to mean the middleware silently did nothing after cold start.
 *
 * This runs the same middleware stack against a **synthesized** request for
 * the target URI. It carries the live session, the launch request's user
 * resolver, its cookies and its server bag — so session-backed middleware
 * (auth, verified, can:) resolves the real user. It does NOT carry a body,
 * query string, uploaded files, or the device's real headers and client IP:
 * the method is always GET, and anything transport-level reads defaults.
 * Middleware needing those should opt out via [skip].
 *
 * Outcome, per navigation:
 *
 *   - pipeline runs to completion  → `null`, the screen mounts
 *   - middleware returns a redirect → REPLACE onto the matching native
 *     screen, or EXIT_WEB when the target is not a native route
 *   - middleware aborts (403, or returns a response) → BACK, the
 *     navigation is refused and the user stays where they were
 */
class ScreenGuard
{
    /**
     * Middleware that must not run per-navigation. These are request-lifecycle
     * concerns that already ran once for the real HTTP request that launched
     * the app; re-running them for every screen push would rotate CSRF tokens,
     * re-open the session, and re-emit cookies against a request that is never
     * actually sent anywhere.
     *
     * @var array<int, class-string>
     */
    public const DEFAULT_SKIP = [
        StartSession::class,
        AuthenticateSession::class,
        VerifyCsrfToken::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        ShareErrorsFromSession::class,
    ];

    /** @var array<int, class-string> */
    public static array $skip = self::DEFAULT_SKIP;

    /**
     * Let an app opt additional middleware out of per-navigation execution —
     * a rate limiter or an analytics logger that should count one visit per
     * app launch, not one per screen push.
     *
     * @param  array<int, class-string>  $middleware
     */
    public static function skip(array $middleware): void
    {
        static::$skip = array_values(array_unique([...static::$skip, ...$middleware]));
    }

    /**
     * Decide whether $uri may be mounted. Null means allow.
     *
     * Fails CLOSED: any exception escaping the pipeline (an unresolvable
     * middleware alias, a guard with a bug) refuses the navigation. Failing
     * open would silently grant access, which is the failure mode this whole
     * mechanism exists to remove.
     */
    public static function check(?LaravelRoute $route, string $uri): ?NavigationIntent
    {
        if ($route === null || $uri === '') {
            return null;
        }

        try {
            return static::run($route, $uri);
        } catch (\Throwable $e) {
            NativeRouter::debugLog("ScreenGuard: {$uri} guard threw ".$e::class.': '.$e->getMessage());

            return new NavigationIntent(NavigationIntent::BACK);
        }
    }

    protected static function run(LaravelRoute $route, string $uri): ?NavigationIntent
    {
        $middleware = static::resolve($route);

        if ($middleware === []) {
            return null;
        }

        $request = static::request($uri, $route);

        // The pipeline's destination must return a real Response, not null:
        // middleware routinely type-hints `$next($request)` as
        // `Symfony\...\Response` (Laravel's own `make:middleware` stub does),
        // and handing it null throws a TypeError. This sentinel is compared
        // by identity below — getting it back means every middleware called
        // $next() and nobody short-circuited.
        $passed = new Response('', SymfonyResponse::HTTP_NO_CONTENT);

        try {
            $response = (new Pipeline(app()))
                ->send($request)
                ->through($middleware)
                ->then(fn () => $passed);
        } catch (AuthenticationException $e) {
            return static::redirect($e->redirectTo() ?? static::loginUri(), $uri);
        } catch (HttpException) {
            return new NavigationIntent(NavigationIntent::BACK);
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
        }

        if ($response === $passed || $response === null) {
            return null;
        }

        if ($response instanceof SymfonyRedirect) {
            return static::redirect($response->getTargetUrl(), $uri);
        }

        if ($response instanceof SymfonyResponse && $response->isRedirection()) {
            return static::redirect((string) $response->headers->get('Location'), $uri);
        }

        // Middleware short-circuited with a rendered response (a 403 page,
        // say). There is nothing to render it into on a native screen, so
        // the navigation is simply refused.
        return new NavigationIntent(NavigationIntent::BACK);
    }

    /**
     * Expand aliases and middleware groups the same way the HTTP kernel
     * does, then drop the request-lifecycle middleware listed in $skip.
     *
     * @return array<int, mixed>
     */
    protected static function resolve(LaravelRoute $route): array
    {
        $gathered = app('router')->resolveMiddleware(
            $route->gatherMiddleware(),
            $route->excludedMiddleware(),
        );

        return array_values(array_filter(
            $gathered,
            fn ($middleware) => ! in_array(
                is_string($middleware) ? strtok($middleware, ':') : $middleware,
                static::$skip,
                true,
            ),
        ));
    }

    /**
     * A request standing in for the navigation. Cookies, server bag and the
     * live session/user resolver are carried over from the request that
     * launched the app so session-backed middleware (auth, verified, can:)
     * resolves the real user rather than a guest.
     */
    protected static function request(string $uri, LaravelRoute $route): Request
    {
        $current = app()->bound('request') && app('request') instanceof Request
            ? app('request')
            : null;

        $request = Request::create(
            $uri,
            'GET',
            [],
            $current?->cookies->all() ?? [],
            [],
            $current?->server->all() ?? [],
        );

        $request->setRouteResolver(fn () => $route);

        if ($current !== null) {
            $request->setUserResolver($current->getUserResolver());
        }

        // The persistent runtime keeps one session store alive across the
        // whole app lifetime, so the navigation can read the same session
        // the launch request opened without StartSession running again.
        if (app()->bound('session.store')) {
            $request->setLaravelSession(app('session.store'));
        }

        return $request;
    }

    /**
     * Turn a redirect target into a navigation intent. A target matching a
     * native route becomes an in-app REPLACE; anything else hands off to the
     * WebView, which is what a redirect to a non-native URL means on device.
     */
    protected static function redirect(?string $target, string $from): NavigationIntent
    {
        if ($target === null || $target === '') {
            return new NavigationIntent(NavigationIntent::BACK);
        }

        $path = '/'.ltrim((string) (parse_url($target, PHP_URL_PATH) ?: '/'), '/');

        // A guard that redirects to the screen it is guarding would bounce
        // forever. Refuse the navigation instead of hanging the runloop.
        if ($path === '/'.ltrim($from, '/')) {
            NativeRouter::debugLog("ScreenGuard: {$from} redirects to itself — refusing navigation");

            return new NavigationIntent(NavigationIntent::BACK);
        }

        if (NativeRouter::resolve($path) !== null) {
            return new NavigationIntent(NavigationIntent::REPLACE, $path);
        }

        return new NavigationIntent(NavigationIntent::EXIT_WEB, $target);
    }

    /** Best-effort login URI for an AuthenticationException with no redirect set. */
    protected static function loginUri(): ?string
    {
        try {
            return app('router')->has('login') ? route('login', absolute: false) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
