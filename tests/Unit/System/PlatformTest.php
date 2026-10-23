<?php

use Native\Mobile\Platform;
use Native\Mobile\System;
use Native\Mobile\Testing\FakeBridge;

afterEach(function () {
    putenv('NATIVEPHP_PLATFORM');
    Platform::set(null);
    FakeBridge::disable();
});

it('takes the platform from the env the shell sets, without asking the device', function (string $platform) {
    putenv("NATIVEPHP_PLATFORM={$platform}");
    $bridge = FakeBridge::enable();

    $system = new System;

    expect($system->platform())->toBe($platform)
        ->and($system->isIos())->toBe($platform === 'ios')
        ->and($system->isAndroid())->toBe($platform === 'android')
        ->and($system->isMobile())->toBeTrue();

    $bridge->assertNotCalled('Device.GetInfo');
})->with(['ios', 'android']);

it('asks the device under Jump, where nothing sets the env', function () {
    $bridge = FakeBridge::enable()->respondTo('Device.GetInfo', [
        'info' => json_encode(['platform' => 'android']),
    ]);

    expect((new System)->isAndroid())->toBeTrue()
        ->and($bridge->callsTo('Device.GetInfo'))->toHaveCount(1);
});

it('is not mobile with no env and no device', function () {
    FakeBridge::enable();

    $system = new System;

    expect($system->platform())->toBeNull()
        ->and($system->isMobile())->toBeFalse();
});

it('ignores an env value that is not a platform', function () {
    putenv('NATIVEPHP_PLATFORM=windows');
    FakeBridge::enable();

    expect((new System)->platform())->toBeNull();
});

it('hands Platform the env value on its first read', function () {
    putenv('NATIVEPHP_PLATFORM=ios');
    $bridge = FakeBridge::enable();

    expect(Platform::current())->toBe('ios');

    $bridge->assertNotCalled('Device.GetInfo');
});
