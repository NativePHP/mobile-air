<?php

use Native\Mobile\Edge\NativeRouter;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;

/*
 * Route patterns are registered as paths. Until this split existed, resolve()
 * matched the whole URI, so `/auth/callback?code=x` missed `/auth/callback` and
 * a deep link carrying any query string resolved to no screen at all — the app
 * either exited to the web or, on a warm link, went nowhere.
 *
 * The parsed query rides back on the resolution so callers can hand it to the
 * screen as navigation data.
 */

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('matches an exact route when the URI carries a query string', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    $resolved = NativeRouter::resolve('/auth/callback?authCode=example');

    expect($resolved)->not->toBeNull()
        ->and($resolved['class'])->toBe(CounterScreen::class)
        ->and($resolved['query'])->toBe(['authCode' => 'example']);
});

it('matches a pattern route and keeps route params and query apart', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    $resolved = NativeRouter::resolve('/detail/7?from=deeplink');

    // A query parameter must not be able to pose as a route segment: `param()`
    // and `data()` answer different questions and the URI says which is which.
    expect($resolved['params'])->toBe(['id' => '7'])
        ->and($resolved['query'])->toBe(['from' => 'deeplink']);
});

it('returns the query parsed rather than as a raw string', function () {
    NativeRouter::register('/search', CounterScreen::class);

    expect(NativeRouter::resolve('/search?q=laravel&page=2')['query'])
        ->toBe(['q' => 'laravel', 'page' => '2']);
});

it('keeps repeated and bracketed parameters as arrays', function () {
    NativeRouter::register('/filter', CounterScreen::class);

    // The shape a Livewire array #[Url] prop serialises to. #143 and #327 both
    // exist because this collapsed to a single value somewhere down the stack.
    expect(NativeRouter::resolve('/filter?tag[]=alpha&tag[]=beta')['query'])
        ->toBe(['tag' => ['alpha', 'beta']]);
});

it('decodes a percent-encoded value exactly once', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    // `%252F` is an encoded `%2F`. Decoding twice would hand the app a `/` and
    // silently corrupt any token that contains one.
    expect(NativeRouter::resolve('/auth/callback?code=a%20b%252Fc')['query'])
        ->toBe(['code' => 'a b%2Fc']);
});

it('reports an empty query when the URI has none', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    expect(NativeRouter::resolve('/auth/callback')['query'])->toBe([]);
});

it('ignores a fragment, on its own or after a query', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    expect(NativeRouter::resolve('/auth/callback#section')['query'])->toBe([])
        ->and(NativeRouter::resolve('/auth/callback#section'))->not->toBeNull()
        ->and(NativeRouter::resolve('/auth/callback?code=x#section')['query'])->toBe(['code' => 'x']);
});

it('treats a bare query string as addressing the root route', function () {
    NativeRouter::register('/', CounterScreen::class);

    $resolved = NativeRouter::resolve('?ref=email');

    expect($resolved)->not->toBeNull()
        ->and($resolved['class'])->toBe(CounterScreen::class)
        ->and($resolved['query'])->toBe(['ref' => 'email']);
});

it('answers isNativeRoute for a URI with a query string', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    // BootPlanner asks this to decide the boot mode. Answering false here is
    // what sent a deep-linked launch to the WebView instead of the screen.
    expect(NativeRouter::isNativeRoute('/auth/callback?authCode=example'))->toBeTrue();
});

it('still resolves nothing for a path that is not registered', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    // The query must not become a way to match a route that does not exist.
    expect(NativeRouter::resolve('/auth/other?authCode=example'))->toBeNull();
});
