<?php

use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\CounterScreen;

// ── Scripted responses ──────────────────────────────

it('answers scripted closures with the decoded call params', function () {
    Native::fakeBridge()->respondTo(
        'Geolocation.GetCurrentPosition',
        fn (array $params) => ['latitude' => $params['fine'] ? 51.5 : 0.0]
    );

    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertSet('lat', 51.5);
});

it('answers scripted raw string responses verbatim', function () {
    Native::fakeBridge()->respondTo(
        'Geolocation.GetCurrentPosition',
        json_encode(['latitude' => 12.3])
    );

    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertSet('lat', 12.3);
});

it('returns null for unscripted calls', function () {
    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertSet('lat', null)
        ->assertNativeCalled('Geolocation.GetCurrentPosition');
});

// ── Recorded calls ──────────────────────────────────

it('exposes the decoded params of recorded calls', function () {
    $calls = Native::test(CounterScreen::class)
        ->call('locate')
        ->bridge()
        ->callsTo('Geolocation.GetCurrentPosition');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['params'])->toBe(['fine' => true]);
});

it('asserts nothing was called when only publishes happened', function () {
    Native::test(CounterScreen::class)
        ->tap('Increment')
        ->bridge()
        ->assertNothingCalled();
});

it('fails assertNothingCalled after a bridge call', function () {
    Native::test(CounterScreen::class)
        ->call('locate')
        ->bridge()
        ->assertNothingCalled();
})->throws(AssertionFailedError::class);

it('fails the params filter when no call matches it', function () {
    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertNativeCalled('Geolocation.GetCurrentPosition', fn (array $params) => $params['fine'] === false);
})->throws(AssertionFailedError::class, 'params filter');

// ── Binding lifecycle ───────────────────────────────

it('reuses the same bridge instance within a test', function () {
    expect(Native::fakeBridge())->toBe(Native::fakeBridge())
        ->and(FakeBridge::current())->toBe(Native::fakeBridge());
});

it('unbinds from the container on disable', function () {
    Native::fakeBridge();
    FakeBridge::disable();

    expect(FakeBridge::current())->toBeNull();
});

// ── Polyfill wiring ─────────────────────────────────

it('captures element lifecycle calls from the polyfills', function () {
    $bridge = Native::fakeBridge();

    nativephp_element_init();
    nativephp_element_reset();
    nativephp_element_shutdown();

    expect($bridge->initCount)->toBe(1)
        ->and($bridge->resetCount)->toBe(1)
        ->and($bridge->shutdownCount)->toBe(1);
});

it('serves runtime flags through the polyfill', function () {
    $bridge = Native::fakeBridge();

    expect(nativephp_runtime_flags())->toBe(0);

    $bridge->runtimeFlags = 3;

    expect(nativephp_runtime_flags())->toBe(3);
});

it('never blocks on wait_event under test', function () {
    Native::fakeBridge();

    expect(nativephp_element_wait_event(60_000))->toBeNull();
});
