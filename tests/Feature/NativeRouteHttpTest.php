<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
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
