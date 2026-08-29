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

it('ignores a query string when deciding routability', function () {
    Route::native('/docs/{section}/{page}', CounterScreen::class);

    // Shared links carry utm tags. The query used to be swallowed by {page},
    // so the link "resolved" to a screen that then found nothing.
    expect(routable('/docs/getting-started/installation?utm_source=twitter'))->toBeTrue();
});

it('does not leak a query string into route params', function () {
    Route::native('/docs/{section}/{page}', CounterScreen::class);

    expect(NativeRouter::resolve('/docs/getting-started/installation?utm_source=twitter')['params'])
        ->toBe(['section' => 'getting-started', 'page' => 'installation']);
});

it('does not bind the shared route object while probing', function () {
    Route::get('/docs/{page}', fn () => 'ok')->name('probe.docs');

    routable('/docs/probe');

    // A read-only question must not leave the collection's Route mutated.
    expect(app('router')->getRoutes()->getByName('probe.docs')->parameters ?? null)->toBeNull();
});
