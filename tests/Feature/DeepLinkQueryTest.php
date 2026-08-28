<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;

/*
 * A deep link arriving while the app is already running is delivered as a
 * `__deeplink` native event, becomes a NAVIGATE intent, and is resolved by
 * NativeRouter like any in-app navigation. Everything below the resolver
 * already preserved the query string; the resolver is where it was lost.
 *
 * Reported as #290: an OAuth callback (`myapp://auth/callback?authCode=...`)
 * reached the screen with no code on it.
 */

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('gives a warm deep link its query parameters as navigation data', function () {
    Route::native('/detail/{id}', DetailScreen::class);

    Native::test(CounterScreen::class)
        ->emitNative('__deeplink', ['uri' => '/detail/7?from=deeplink'])
        ->assertNavigatedTo('/detail/7?from=deeplink')
        ->followNavigation()
        ->assertScreen(DetailScreen::class)
        ->assertSee('Detail 7 from deeplink');
});

it('lands the OAuth callback shape from the report', function () {
    Route::native('/auth/callback', DetailScreen::class);

    // `myapp://auth/callback?authCode=example`, normalised by DeepLinkRouter.
    $screen = Native::test(CounterScreen::class)
        ->emitNative('__deeplink', ['uri' => '/auth/callback?authCode=example'])
        ->followNavigation();

    expect($screen->instance()->data('authCode'))->toBe('example');
});

it('prefers an explicit navigate() payload over the query string', function () {
    Route::native('/detail/{id}', DetailScreen::class);

    // The URI is where the value came from; the payload is what the caller
    // asked for. The caller is more specific, so it wins.
    Native::test(CounterScreen::class)
        ->call('navigate', '/detail/7?from=query', ['from' => 'payload'])
        ->followNavigation()
        ->assertSee('Detail 7 from payload');
});

it('exposes the query to Native::visit() the way the device does', function () {
    Route::native('/detail/{id}', DetailScreen::class);

    Native::visit('/detail/9?from=visit')
        ->assertSee('Detail 9 from visit');
});

it('restores query data onto a hot-reloaded stack', function () {
    Route::native('/detail/{id}', DetailScreen::class);

    // preloadStack replays the entries below the top after a PHP reboot. It
    // reads the same stored URIs, so it needs the query off them too —
    // otherwise a save while deep-linked drops back a screen with no data.
    $router = new class extends NativeRouter
    {
        public function restored(): array
        {
            return $this->stack;
        }
    };

    $router->preloadStack([['uri' => '/detail/3?from=restored']]);

    expect($router->stackDepth())->toBe(1);

    $component = $router->restored()[0]['component'];

    expect($component->param('id'))->toBe('3')
        ->and($component->data('from'))->toBe('restored');
});
