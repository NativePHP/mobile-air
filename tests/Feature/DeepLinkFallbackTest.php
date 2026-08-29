<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\NativeRouter;
use Tests\Fixtures\Edge\CounterScreen;

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

/** Reach the protected helper the __deeplink handler gates on. */
function routable(string $uri): bool
{
    return (fn () => static::routableDeepLink($uri))->call(new CounterScreen);
}

it('treats a native route as routable', function () {
    Route::native('/docs/{section}/{page}', CounterScreen::class);

    expect(routable('/docs/getting-started/installation'))->toBeTrue();
});

it('treats a plain Laravel route as routable', function () {
    // WebView and hybrid apps deep link to these; they render in the WebView.
    Route::get('/receipts/{id}', fn () => 'ok');

    expect(routable('/receipts/42'))->toBeTrue();
});

it('does not treat an unrouted path as routable', function () {
    Route::native('/docs/{section}/{page}', CounterScreen::class);

    // The reported bug: a marketing page on the claimed domain, and a real docs
    // URL one segment deeper than the route that claims it. Both used to exit to
    // the WebView and serve a local 404.
    expect(routable('/blog'))->toBeFalse()
        ->and(routable('/docs/plugins/core/camera'))->toBeFalse();
});

it('respects an app-registered fallback route', function () {
    Route::fallback(fn () => 'caught');

    expect(routable('/anything-at-all'))->toBeTrue();
});
