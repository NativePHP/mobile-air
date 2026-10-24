<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Edge\ScreenGuard;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\AllowMiddleware;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DenyMiddleware;
use Tests\Fixtures\Edge\GuardedScreen;

beforeEach(function () {
    NativeRouter::clearRoutes();
    AllowMiddleware::reset();
    DenyMiddleware::reset();
    GuardedScreen::reset();
    ScreenGuard::$skip = ScreenGuard::DEFAULT_SKIP;
    ScreenGuard::onFailure(null);
});

afterEach(function () {
    NativeRouter::clearRoutes();
    ScreenGuard::onFailure(null);
});

// ── The reported bug (#252) ─────────────────────────

it('runs route middleware for a screen reached by in-app navigation', function () {
    Route::native('/guarded', GuardedScreen::class)->middleware(DenyMiddleware::class);
    Route::native('/login', CounterScreen::class);

    Native::visit('/guarded')->assertReplacedWith('/login');

    expect(DenyMiddleware::$seen)->toBe(['guarded']);
});

it('never mounts a screen whose middleware refused the navigation', function () {
    Route::native('/guarded', GuardedScreen::class)->middleware(DenyMiddleware::class);
    Route::native('/login', CounterScreen::class);

    Native::visit('/guarded')->assertReplacedWith('/login');

    // The whole point: a guarded screen must not run its data loading before
    // being turned away. A refused navigation publishes no frame either —
    // there is no render to leak content from.
    expect(GuardedScreen::$mounts)->toBe(0);
});

it('mounts the screen when middleware allows the navigation', function () {
    Route::native('/guarded', GuardedScreen::class)->middleware(AllowMiddleware::class);

    Native::visit('/guarded')->assertSee('Guarded screen content');

    expect(AllowMiddleware::$seen)->toBe(['guarded']);
    expect(GuardedScreen::$mounts)->toBe(1);
});

// ── How the middleware gets attached ────────────────

it('picks up middleware chained after Route::native() returns', function () {
    // The macro hands the live Route object to the registry, so middleware
    // added by the caller afterwards is still seen at navigation time.
    Route::native('/guarded', GuardedScreen::class)->middleware(AllowMiddleware::class);

    Native::visit('/guarded');

    expect(AllowMiddleware::$seen)->toBe(['guarded']);
});

it('picks up middleware from a surrounding route group', function () {
    // The v3 → v4 migration shape from the issue: a group wrapping many
    // native routes, with no per-route middleware at all.
    Route::middleware([DenyMiddleware::class])->group(function () {
        Route::native('/guarded', GuardedScreen::class);
    });
    Route::native('/login', CounterScreen::class);

    Native::visit('/guarded')->assertReplacedWith('/login');

    expect(DenyMiddleware::$seen)->toBe(['guarded']);
    expect(GuardedScreen::$mounts)->toBe(0);
});

it('survives the web group that withRouting() puts every native route in', function () {
    // `withRouting(web: routes/mobile.php)` is the normal way to register
    // native routes, and it wraps every one of them in the `web` group. Most
    // of that group is skipped, but SubstituteBindings runs — and it calls
    // $route->parameters(), which throws "Route is not bound" unless the
    // route has been bound to the request. Failing closed then refuses every
    // navigation in the entire app.
    registerWebGroup();

    Route::middleware('web')->group(function () {
        Route::native('/plain', GuardedScreen::class);
        Route::native('/bound/{id}', GuardedScreen::class);
    });

    Native::visit('/plain')->assertSee('Guarded screen content');
    Native::visit('/bound/42')->assertSee('Guarded screen content');

    expect(GuardedScreen::$mounts)->toBe(2);
});

it('expands a group the HTTP kernel has not synced to the router yet', function () {
    // The router only learns its middleware groups when the HTTP kernel is
    // constructed. A console process (artisan, a test, a queue worker) can
    // reach a native route without ever having built one, and the `web`
    // group would then be treated as a class name: the pipeline fails to
    // resolve it, the guard fails closed, and every navigation is refused.
    app()->forgetInstance(HttpKernelContract::class);
    app()->singleton(HttpKernelContract::class, fn ($app) => new class($app, $app['router']) extends HttpKernel
    {
        protected $middlewareGroups = ['kernel-only' => [DenyMiddleware::class]];
    });

    expect(app('router')->getMiddlewareGroups())->not->toHaveKey('kernel-only');

    Route::native('/guarded', GuardedScreen::class)->middleware('kernel-only');
    Route::native('/login', CounterScreen::class);

    Native::visit('/guarded')->assertReplacedWith('/login');

    expect(DenyMiddleware::$seen)->toBe(['guarded']);
});

it('does not mutate the route instance the HTTP router matches against', function () {
    // The guard binds a clone; binding the registry's own instance would
    // leave stale parameters on the route real requests still match.
    registerWebGroup();

    Route::middleware('web')->group(function () {
        Route::native('/bound/{id}', GuardedScreen::class);
    });

    $route = NativeRouter::registeredRoutes()['/bound/{id}']['route'];

    // In-app navigation hands the guard this registered instance.
    ScreenGuard::check($route, '/bound/42');

    expect(fn () => $route->parameters())->toThrow(LogicException::class);
});

it('leaves routes without middleware untouched', function () {
    Route::native('/guarded', GuardedScreen::class);

    Native::visit('/guarded')->assertSee('Guarded screen content');

    expect(GuardedScreen::$mounts)->toBe(1);
});

// ── Redirect mapping ────────────────────────────────

it('exits to the web when the redirect target is not a native route', function () {
    DenyMiddleware::$redirectTo = '/billing/upgrade';

    Route::native('/guarded', GuardedScreen::class)->middleware(DenyMiddleware::class);

    // The WebView needs a loadable URL, so EXIT_WEB carries the redirect's
    // full target rather than just its path.
    Native::visit('/guarded')->assertExitedToWeb(url('/billing/upgrade'));
});

it('refuses rather than looping when a guard redirects to its own screen', function () {
    DenyMiddleware::$redirectTo = '/guarded';

    Route::native('/guarded', GuardedScreen::class)->middleware(DenyMiddleware::class);

    Native::visit('/guarded')->assertWentBack();

    expect(GuardedScreen::$mounts)->toBe(0);
});

// ── Request-lifecycle middleware is skipped ─────────

it('does not re-run session middleware per navigation', function () {
    // StartSession et al already ran for the real HTTP request that launched
    // the app; re-running them per screen push would reopen the session and
    // rotate CSRF tokens against a request that is never sent anywhere.
    Route::native('/guarded', GuardedScreen::class)
        ->middleware([StartSession::class, AllowMiddleware::class]);

    Native::visit('/guarded')->assertSee('Guarded screen content');

    // The non-lifecycle middleware in the same stack still ran.
    expect(AllowMiddleware::$seen)->toBe(['guarded']);
});

it('lets an app opt its own middleware out of per-navigation runs', function () {
    ScreenGuard::skip([AllowMiddleware::class]);

    Route::native('/guarded', GuardedScreen::class)->middleware(AllowMiddleware::class);

    Native::visit('/guarded')->assertSee('Guarded screen content');

    expect(AllowMiddleware::$seen)->toBe([]);
});

// ── Guard failure must not fail open ────────────────

it('sends the user to the start_url when the middleware itself blows up', function () {
    // An unresolvable alias throws inside the pipeline. Failing OPEN here
    // would silently grant access, the exact bug this mechanism exists to
    // fix. Refusing in place can leave the app on a blank screen, so the
    // user lands on the start screen and the exception is reported.
    $reported = captureReports();

    Route::native('/', CounterScreen::class);
    Route::native('/guarded', GuardedScreen::class)->middleware('no-such-middleware-alias');

    Native::visit('/guarded')->assertReplacedWith('/');

    expect(GuardedScreen::$mounts)->toBe(0);
    expect($reported)->toHaveCount(1);
});

it('follows the configured start_url after a guard failure', function () {
    config(['nativephp.start_url' => '/home']);

    Route::native('/home', CounterScreen::class);
    Route::native('/guarded', GuardedScreen::class)->middleware('no-such-middleware-alias');

    Native::visit('/guarded')->assertReplacedWith('/home');
});

it('lets the app decide where a failed guard sends the user', function () {
    $failed = null;

    ScreenGuard::onFailure(function (Throwable $e, string $uri) use (&$failed) {
        $failed = $uri;

        return '/oops';
    });

    Route::native('/oops', CounterScreen::class);
    Route::native('/guarded', GuardedScreen::class)->middleware('no-such-middleware-alias');

    Native::visit('/guarded')->assertReplacedWith('/oops');

    expect($failed)->toBe('/guarded');
    expect(GuardedScreen::$mounts)->toBe(0);
});

it('lets the app keep the user where they were after a guard failure', function () {
    ScreenGuard::onFailure(fn () => new NavigationIntent(NavigationIntent::BACK));

    Route::native('/guarded', GuardedScreen::class)->middleware('no-such-middleware-alias');

    Native::visit('/guarded')->assertWentBack();
});

it('hands a non-native failure destination to the web', function () {
    ScreenGuard::onFailure(fn () => 'https://example.com/help');

    Route::native('/guarded', GuardedScreen::class)->middleware('no-such-middleware-alias');

    Native::visit('/guarded')->assertExitedToWeb('https://example.com/help');
});

it('hands the start screen to the web when its own guard is the one that failed', function () {
    // Replacing onto the screen that just failed would fail again, forever.
    // A real request lets the HTTP kernel run the middleware itself and
    // show Laravel's error page if it really is broken.
    Route::native('/', GuardedScreen::class)->middleware('no-such-middleware-alias');

    Native::visit('/')->assertExitedToWeb('/');

    expect(GuardedScreen::$mounts)->toBe(0);
});

it('still sends the user somewhere when the failure callback throws', function () {
    $reported = captureReports();

    ScreenGuard::onFailure(fn () => throw new RuntimeException('callback broke'));

    Route::native('/', CounterScreen::class);
    Route::native('/guarded', GuardedScreen::class)->middleware('no-such-middleware-alias');

    Native::visit('/guarded')->assertReplacedWith('/');

    expect($reported)->toHaveCount(2);
});

/**
 * Collect what the guard hands to Laravel's reporter.
 *
 * @return ArrayObject<int, Throwable>
 */
function captureReports(): ArrayObject
{
    $reported = new ArrayObject;

    app(ExceptionHandler::class)->reportable(function (Throwable $e) use ($reported) {
        $reported[] = $e;

        return false;
    });

    return $reported;
}

/**
 * The `web` group as a real app has it. Testbench doesn't register one, but
 * `withRouting(web: routes/mobile.php)` puts every native route inside it —
 * so this is the shape the guard actually meets in production.
 */
function registerWebGroup(): void
{
    // Build the kernel first. Its constructor syncs its own groups onto the
    // router, which would replace this one if it were built afterwards.
    app(HttpKernelContract::class);

    app('router')->middlewareGroup('web', [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        SubstituteBindings::class,
    ]);
}
