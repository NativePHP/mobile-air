<?php

use Native\Mobile\Testing\FakeBridge;

afterEach(fn () => FakeBridge::disable());

it('assumes every capability is available by default', function () {
    FakeBridge::enable();

    expect(nativephp_can('Camera.GetPhoto'))->toBeTrue()
        ->and(nativephp_can('Anything.AtAll'))->toBeTrue();
});

it('lets a fake deny capabilities so gated fallbacks are testable', function () {
    FakeBridge::enable()->withoutCapability('Camera.GetPhoto', 'Nfc.Scan');

    expect(nativephp_can('Camera.GetPhoto'))->toBeFalse()
        ->and(nativephp_can('Nfc.Scan'))->toBeFalse()
        ->and(nativephp_can('Dialog.Alert'))->toBeTrue();
});

it('answers true with no bridge at all', function () {
    FakeBridge::disable();

    expect(nativephp_can('Camera.GetPhoto'))->toBeTrue();
});
