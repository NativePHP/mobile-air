<?php

/**
 * Plugin-authored test vocabulary: FakeBridge macros and their forwarding
 * through the TestableComponent harness. This is the extension point that
 * lets a plugin ship sugar like assertCopied() instead of exposing raw
 * bridge method strings to app tests.
 */

use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;

// ── Macros on the bridge itself ─────────────────────

it('runs a plugin-registered assertion macro on the bridge', function () {
    FakeBridge::macro('assertLocateRequested', function () {
        return $this->assertCalled('Geolocation.GetCurrentPosition');
    });

    $bridge = Native::fakeBridge();

    Native::test(CounterScreen::class)->call('locate');

    $bridge->assertLocateRequested();
});

it('binds macros to the bridge instance so scripting helpers work', function () {
    FakeBridge::macro('withLocation', function (float $lat) {
        return $this->respondTo('Geolocation.GetCurrentPosition', ['latitude' => $lat]);
    });

    Native::fakeBridge()->withLocation(48.85);

    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertSet('lat', 48.85);
});

it('fails macro assertions with the underlying bridge message', function () {
    FakeBridge::macro('assertLocatedOnce', function () {
        return $this->assertCalledTimes('Geolocation.GetCurrentPosition', 1);
    });

    Native::fakeBridge();

    expect(fn () => Native::test(CounterScreen::class)->assertLocatedOnce())
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

// ── Forwarding through the harness ──────────────────

it('forwards macros through the harness and keeps the chain fluent', function () {
    FakeBridge::macro('assertAskedForPosition', function () {
        return $this->assertCalled('Geolocation.GetCurrentPosition');
    });

    Native::fakeBridge()->respondTo('Geolocation.GetCurrentPosition', ['latitude' => 51.5]);

    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertAskedForPosition()   // macro resolved on the bridge...
        ->assertSet('lat', 51.5);    // ...chain continues on the harness
});

it('forwards built-in bridge helpers through the harness', function () {
    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertCalled('Geolocation.GetCurrentPosition')
        ->assertSet('lat', null);
});

it('returns non-fluent macro results verbatim', function () {
    FakeBridge::macro('locateCallCount', function () {
        return count($this->callsTo('Geolocation.GetCurrentPosition'));
    });

    $count = Native::test(CounterScreen::class)
        ->call('locate')
        ->locateCallCount();

    expect($count)->toBe(1);
});

it('throws a BadMethodCallException for unknown methods', function () {
    expect(fn () => Native::test(CounterScreen::class)->assertTeleported())
        ->toThrow(BadMethodCallException::class, 'assertTeleported');
});
