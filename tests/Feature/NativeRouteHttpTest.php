<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;

beforeEach(function () {
    NativeRouter::clearRoutes();
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

it('answers native routes with a stub response instead of entering the runloop', function () {
    // This asserts the UNBOUND default — a package may have bound a
    // NativeRouteFallback, which takes precedence over the stub.
    unset(app()[NativeRouteFallback::class]);

    Route::native('/native-home', CounterScreen::class);

    $this->get('/native-home')
        ->assertSuccessful()
        ->assertSee(CounterScreen::class)
        ->assertSee('Native::test()', false);
});

it('registers native routes with the router for the component harness', function () {
    Route::native('/native-detail/{id}', DetailScreen::class);

    Native::visit('/native-detail/42', ['from' => 'http-route'])
        ->assertSee('Detail 42 from http-route');
});

it('registers a native route inside a prefixed group under the prefixed URI', function () {
    Route::prefix('app')->group(function () {
        Route::native('/settings', CounterScreen::class);
    });

    expect(array_keys(NativeRouter::registeredRoutes()))->toBe(['/app/settings'])
        ->and(NativeRouter::resolve('/app/settings')['class'] ?? null)->toBe(CounterScreen::class)
        ->and(NativeRouter::isNativeRoute('/settings'))->toBeFalse();

    $this->get('/app/settings')->assertSuccessful()->assertSee(CounterScreen::class);
});

it('registers a prefixed root as the bare prefix', function () {
    Route::prefix('app')->group(function () {
        Route::native('/', CounterScreen::class);
    });

    expect(array_keys(NativeRouter::registeredRoutes()))->toBe(['/app']);
});

it('keeps a layout set on a prefixed route under the same key', function () {
    Route::prefix('app')->group(function () {
        Route::native('/settings', CounterScreen::class)->layout(ChromeTabsLayout::class);
    });

    expect(NativeRouter::registeredRoutes())->toBe([
        '/app/settings' => ['class' => CounterScreen::class, 'layout' => ChromeTabsLayout::class],
    ]);
});

it('applies a nativeGroup layout to routes in a prefixed group', function () {
    Route::prefix('app')->group(function () {
        Route::nativeGroup(ChromeTabsLayout::class, function () {
            Route::native('/feed', CounterScreen::class);
        });
    });

    expect(NativeRouter::resolve('/app/feed')['layout'] ?? null)->toBe(ChromeTabsLayout::class);
});

it('resolves params under a prefix, including params in the prefix', function () {
    Route::prefix('app')->group(function () {
        Route::native('/items/{id}', DetailScreen::class);
    });

    Route::prefix('{team}')->group(function () {
        Route::native('/projects/{id}', DetailScreen::class);
    });

    expect(NativeRouter::resolve('/app/items/7')['params'] ?? null)->toBe(['id' => '7'])
        ->and(NativeRouter::resolve('/acme/projects/42')['params'] ?? null)->toBe(['team' => 'acme', 'id' => '42']);

    Native::visit('/acme/projects/42', ['from' => 'a team'])
        ->assertSee('Detail 42 from a team');
});

it('registers routes outside a prefix exactly as before', function () {
    Route::native('/', CounterScreen::class);
    Route::native('/settings', CounterScreen::class)->layout(ChromeTabsLayout::class);
    Route::native('items/{id}', DetailScreen::class);

    expect(NativeRouter::registeredRoutes())->toBe([
        '/' => ['class' => CounterScreen::class, 'layout' => null],
        '/settings' => ['class' => CounterScreen::class, 'layout' => ChromeTabsLayout::class],
        '/items/{id}' => ['class' => DetailScreen::class, 'layout' => null],
    ])->and(NativeRouter::resolve('/items/9')['params'] ?? null)->toBe(['id' => '9']);
});
