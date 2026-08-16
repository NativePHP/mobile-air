<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Testing\Native;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;
use Tests\Fixtures\Edge\GateScreen;
use Tests\Fixtures\Edge\NavScreen;
use Tests\Fixtures\Edge\PingReceived;

beforeEach(function () {
    NativeRouter::clearRoutes();
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

it('mounts a component and renders its first frame', function () {
    Native::test(CounterScreen::class)
        ->assertSee('Count: 0')
        ->assertDontSee('Feature enabled');
});

it('taps a pressable by its visible text', function () {
    Native::test(CounterScreen::class)
        ->tap('Increment')
        ->assertSet('count', 1)
        ->assertSee('Count: 1');
});

it('presses a callback by method name, including bound arguments', function () {
    Native::test(CounterScreen::class)
        ->press('add')
        ->assertSet('count', 5)
        ->assertSee('Count: 5');
});

it('resolves computed properties in assertions and renders', function () {
    Native::test(CounterScreen::class)
        ->tap('Increment')
        ->assertSet('doubled', 2)
        ->assertSee('Doubled: 2');
});

it('sets model-bound properties and fires the updated hook', function () {
    Native::test(CounterScreen::class)
        ->set('query', 'hello')
        ->assertSet('query', 'hello')
        ->assertSet('lastHook', 'query:hello')
        ->assertSee('Query: hello');
});

it('types into a model-bound input via the wire event path', function () {
    Native::test(CounterScreen::class)
        ->input('query', 'abc')
        ->assertSet('query', 'abc')
        ->assertSet('lastHook', 'query:abc');
});

it('fails loudly when setting a property that does not exist', function () {
    Native::test(CounterScreen::class)->set('missing', 'x');
})->throws(AssertionFailedError::class);

it('dispatches toggle change events', function () {
    Native::test(CounterScreen::class)
        ->toggle('setEnabled', true)
        ->assertSet('enabled', true)
        ->assertSee('Feature enabled');
});

it('delivers native events to #[On] listeners', function () {
    Native::test(CounterScreen::class)
        ->emitNative(PingReceived::class, ['message' => 'yo'])
        ->assertSet('pings', ['yo'])
        ->emitNative(PingReceived::class, ['message' => 'again'])
        ->assertSet('pings', ['yo', 'again']);
});

it('records native bridge calls and plays back scripted responses', function () {
    Native::fakeBridge()->respondTo('Geolocation.GetCurrentPosition', [
        'latitude' => 40.7,
        'longitude' => -74.0,
    ]);

    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertSet('lat', 40.7)
        ->assertNativeCalled('Geolocation.GetCurrentPosition', fn (array $params) => $params['fine'] === true)
        ->assertNativeNotCalled('Camera.GetPhoto');
});

it('asserts on navigation intents', function () {
    Native::test(CounterScreen::class)
        ->assertNoNavigation()
        ->tap('Open detail')
        ->assertNavigatedTo('/detail/7');
});

it('asserts back navigation', function () {
    Native::test(DetailScreen::class)
        ->pressBack()
        ->assertWentBack();
});

it('asserts exit-to-web navigation', function () {
    Native::test(CounterScreen::class)
        ->call('leaveToWeb')
        ->assertExitedToWeb('/settings');
});

it('keeps direct navigation callbacks working for compatibility', function () {
    Native::test(NavScreen::class)
        ->tap('Direct push')
        ->assertNavigatedTo('/detail/9');

    Native::test(NavScreen::class)
        ->tap('Direct replace')
        ->assertReplacedWith('/login');

    Native::test(NavScreen::class)
        ->tap('Direct exit')
        ->assertExitedToWeb('/settings');

    Native::test(NavScreen::class)
        ->tap('Direct back')
        ->assertWentBack();
});

it('follows navigation onto the next screen with params and data', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    Native::test(CounterScreen::class)
        ->tap('Open detail')
        ->followNavigation()
        ->assertSee('Detail 7 from counter');
});

it('visits a registered route with resolved params', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    Native::visit('/detail/42')
        ->assertSee('Detail 42 from nowhere');
});

it('refuses interaction after the component navigated away', function () {
    Native::test(CounterScreen::class)
        ->tap('Open detail')
        ->tap('Increment');
})->throws(AssertionFailedError::class);

it('honors a redirect set during mount without rendering', function () {
    Native::test(GateScreen::class)
        ->assertReplacedWith('/detail/1');
});

it('carries a custom transition on a push', function () {
    Native::test(NavScreen::class)
        ->tap('Push with transition')
        ->assertNavigatedTo('/detail/7')
        ->assertTransition(Transition::SlideFromBottom);
});

it('carries a custom transition on a replace', function () {
    Native::test(NavScreen::class)
        ->tap('Replace with transition')
        ->assertReplacedWith('/login')
        ->assertTransition('fade');
});

it('resolves a named route for navigation', function () {
    Route::native('/listing/{id}', DetailScreen::class)->name('listing.show');

    Native::test(NavScreen::class)
        ->tap('Push named')
        ->assertNavigatedTo('/listing/5');
});

it('pops the stack on a default device-back press', function () {
    Native::test(NavScreen::class)
        ->pressBack()
        ->assertWentBack();
});

it('lets onBackPressed intercept the device-back press', function () {
    Native::test(NavScreen::class)
        ->set('dirty', true)
        ->pressBack()
        ->assertNoNavigation()
        ->assertSet('discardShown', true);
});

it('finds elements by wire type with an optional matcher', function () {
    Native::test(CounterScreen::class)
        ->assertElement('button', fn (array $node) => ($node['props']['label'] ?? null) === 'Increment')
        ->assertElement('toggle')
        ->assertMissingElement('slider');
});

it('exposes the raw wire tree for custom assertions', function () {
    $tree = Native::test(CounterScreen::class)->tree();

    expect($tree['type'])->toBe('column')
        ->and($tree['children'])->not->toBeEmpty();
});

it('isolates fake bridge state between components in the same test', function () {
    $bridge = Native::fakeBridge();

    Native::test(CounterScreen::class)->tap('Increment');

    expect($bridge->publishes)->not->toBeEmpty()
        ->and($bridge->lastPublish()['type'])->toBe('column');
});
