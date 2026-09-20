<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\NativeRouter;
use Tests\Fixtures\Edge\CounterScreen;

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('lets a bound fallback answer a native route reached without a runtime', function () {
    Route::native('/counter-fallback', CounterScreen::class);

    app()->singleton(NativeRouteFallback::class, fn () => new class implements NativeRouteFallback
    {
        public function handle(string $componentClass)
        {
            return response('Get the app to view '.class_basename($componentClass), 200);
        }
    });

    $this->get('/counter-fallback')
        ->assertOk()
        ->assertSee('Get the app to view CounterScreen');
});

it('changes nothing when no fallback is bound', function () {
    // Clean container — another package may have bound a fallback.
    unset(app()[NativeRouteFallback::class]);

    Route::native('/counter-plain', CounterScreen::class);

    $this->get('/counter-plain')
        ->assertOk()
        ->assertSee('test it with Native::test()');
});
