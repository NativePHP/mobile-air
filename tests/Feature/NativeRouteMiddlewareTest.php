<?php

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\NativeRouter;
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
});

afterEach(function () {
    NativeRouter::clearRoutes();
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

it('refuses the navigation when the middleware itself blows up', function () {
    // An unresolvable alias throws inside the pipeline. Failing OPEN here
    // would silently grant access — the exact bug this mechanism exists to
    // fix — so the router turns any guard exception into a refusal.
    Route::native('/guarded', GuardedScreen::class)->middleware('no-such-middleware-alias');

    Native::visit('/guarded')->assertWentBack();

    expect(GuardedScreen::$mounts)->toBe(0);
});
