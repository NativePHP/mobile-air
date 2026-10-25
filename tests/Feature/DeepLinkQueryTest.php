<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\QueryScreen;

/*
 * A deep link arriving while the app is already running is delivered as a
 * `__deeplink` native event, becomes a NAVIGATE intent, and is resolved by
 * NativeRouter like any in-app navigation. Its query string reaches the screen
 * as query(), never as data(): anyone can open a link to the app, and data()
 * only holds what the app passed itself.
 *
 * Reported as #290: an OAuth callback (`myapp://auth/callback?authCode=...`)
 * reached the screen with no code on it.
 */

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('gives a warm deep link its query', function () {
    Route::native('/auth/callback', QueryScreen::class);

    // myapp://auth/callback?code=a%2Bb%3D%3D&state=x%26y, as the shells send it.
    Native::test(CounterScreen::class)
        ->emitNative('__deeplink', ['uri' => '/auth/callback?code=a%2Bb%3D%3D&state=x%26y'])
        ->followNavigation()
        ->assertScreen(QueryScreen::class)
        ->assertSee('code "a+b=="')
        ->assertSee('"state":"x&y"');
});

it('keeps a deep link query out of data()', function () {
    Route::native('/checkout', QueryScreen::class);

    Native::test(CounterScreen::class)
        ->emitNative('__deeplink', ['uri' => '/checkout?from=link'])
        ->followNavigation()
        ->assertSee('from data "nowhere"')
        ->assertSee('"from":"link"');
});

it('keeps a navigate() payload and the query apart', function () {
    Route::native('/detail', QueryScreen::class);

    Native::test(CounterScreen::class)
        ->call('navigate', '/detail?from=query', ['from' => 'payload'])
        ->followNavigation()
        ->assertSee('from data "payload"')
        ->assertSee('"from":"query"');
});

it('gives Native::visit() the query the way the device does, arrays and all', function () {
    Route::native('/search', QueryScreen::class);

    Native::visit('/search?code=x&tag[]=alpha')
        ->assertSee('code "x"')
        ->assertSee('first tag "alpha"');
});

it('gives a screen with no query string an empty query', function () {
    Route::native('/search', QueryScreen::class);

    Native::visit('/search')
        ->assertSee('query []')
        ->assertSee('code "none"');
});

it('gives the launch screen the query from the URI it started at', function () {
    // A cold deep link: the provider hands start() the request's path and its
    // raw query string. Stop before the runloop and look at what was built.
    $router = new class extends NativeRouter
    {
        public array $launched = [];

        protected function loop(): ?string
        {
            $this->launched = $this->stack;

            return null;
        }
    };

    $router->start(QueryScreen::class, [], '/auth/callback?code=a%2Bb%3D%3D');

    $screen = $router->launched[0]['component'];

    // The stack records the launch screen by its path, as it always has.
    expect((fn () => $this->query('code'))->call($screen))->toBe('a+b==')
        ->and($router->launched[0]['uri'])->toBe('/auth/callback');
});

it('restores the query onto a hot-reloaded stack', function () {
    Route::native('/search', QueryScreen::class);

    // preloadStack replays the entries below the top after a PHP reboot, from
    // the URIs the stack stored, query strings and all.
    $router = new class extends NativeRouter
    {
        public function restored(): array
        {
            return $this->stack;
        }
    };

    $router->preloadStack([['uri' => '/search?code=restored']]);

    $screen = $router->restored()[0]['component'];

    expect((fn () => $this->query('code'))->call($screen))->toBe('restored');
});
