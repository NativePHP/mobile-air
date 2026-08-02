<?php

use Native\Mobile\Enums\Os;
use Native\Mobile\Platform;
use Native\Mobile\Testing\FakeBridge;

afterEach(function () {
    Platform::set(null);
    FakeBridge::disable();
});

// ── Platform::set() ─────────────────────────────────

it('accepts an enum case', function () {
    Platform::set(Os::Android);

    expect(Platform::isAndroid())->toBeTrue()
        ->and(Platform::isIos())->toBeFalse()
        ->and(Platform::current())->toBe('android')
        ->and(Platform::os())->toBe(Os::Android);
});

it('still accepts the string spellings it always has', function () {
    Platform::set('ios');

    expect(Platform::isIos())->toBeTrue()
        ->and(Platform::current())->toBe('ios')
        ->and(Platform::os())->toBe(Os::Ios);
});

it('tolerates the casing people actually write', function () {
    Platform::set('iOS');

    expect(Platform::os())->toBe(Os::Ios);
});

it('rejects an unknown platform instead of storing it', function () {
    // The bug this closes: set('androdi') used to be accepted silently and
    // left isAndroid() false for the rest of the process.
    expect(fn () => Platform::set('androdi'))
        ->toThrow(InvalidArgumentException::class);
});

it('resets to lazy detection on null', function () {
    Platform::set(Os::Ios);
    expect(Platform::os())->toBe(Os::Ios);

    Platform::set(null);

    expect(Platform::current())->toBeNull()
        ->and(Platform::os())->toBeNull();
});

it('keeps the long-standing constants in step with the enum', function () {
    expect(Platform::IOS)->toBe(Os::Ios->value)
        ->and(Platform::ANDROID)->toBe(Os::Android->value);
});

it('exposes the platform check on the enum itself', function () {
    expect(Os::Ios->isIos())->toBeTrue()
        ->and(Os::Ios->isAndroid())->toBeFalse()
        ->and(Os::fromLabel('nope'))->toBeNull();
});

// ── Capability seam ─────────────────────────────────

it('reports every bridge function as available by default', function () {
    $bridge = FakeBridge::enable();

    expect(nativephp_can('Toast.Show'))->toBeTrue();

    $bridge->assertCapabilityChecked('Toast.Show');
});

it('lets a test deny a bridge function so the fallback path runs', function () {
    // Before this seam the fallback arm of every nativephp_can() check was
    // unreachable from the suite, because the polyfill hardcoded `true`.
    $bridge = FakeBridge::enable()->cannot('Toast.Show');

    expect(nativephp_can('Toast.Show'))->toBeFalse()
        ->and(nativephp_can('Dialog.Toast'))->toBeTrue();
});

it('can deny several bridge functions at once', function () {
    FakeBridge::enable()->cannot('Toast.Show', 'Toast.Dismiss');

    expect(nativephp_can('Toast.Show'))->toBeFalse()
        ->and(nativephp_can('Toast.Dismiss'))->toBeFalse();
});
