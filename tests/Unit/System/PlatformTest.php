<?php

use Native\Mobile\Platform;
use Native\Mobile\System;
use Native\Mobile\Testing\FakeBridge;

afterEach(function () {
    putenv('NATIVEPHP_PLATFORM');
    System::rememberPlatform(null);
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

it('asks the device once under Jump, where nothing sets the env', function () {
    $bridge = FakeBridge::enable()->respondTo('Device.GetInfo', [
        'info' => json_encode(['platform' => 'android']),
    ]);

    $system = new System;

    expect($system->isAndroid())->toBeTrue()
        ->and($system->isMobile())->toBeTrue()
        ->and($bridge->callsTo('Device.GetInfo'))->toHaveCount(1);
});

it('does not keep a failed probe, but waits before asking again', function () {
    $bridge = FakeBridge::enable();
    $system = new System;

    expect($system->platform())->toBeNull();

    // The device attaches a moment later. Within the throttle window the
    // answer is still null, without another round-trip.
    $bridge->respondTo('Device.GetInfo', ['info' => json_encode(['platform' => 'ios'])]);

    expect($system->platform())->toBeNull()
        ->and($bridge->callsTo('Device.GetInfo'))->toHaveCount(1);

    System::rememberPlatform(null);

    expect($system->platform())->toBe('ios');
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

it('lets the env win over a remembered platform', function () {
    System::rememberPlatform('android');
    putenv('NATIVEPHP_PLATFORM=ios');

    expect((new System)->platform())->toBe('ios');
});

it('forgets the last fake device when a new test enables its bridge', function () {
    FakeBridge::enable()->respondTo('Device.GetInfo', ['info' => json_encode(['platform' => 'android'])]);
    expect((new System)->platform())->toBe('android');

    FakeBridge::disable();
    FakeBridge::enable()->respondTo('Device.GetInfo', ['info' => json_encode(['platform' => 'ios'])]);

    expect((new System)->platform())->toBe('ios');
});

it('keeps the deprecated Platform class working on top of System', function () {
    putenv('NATIVEPHP_PLATFORM=ios');

    expect(Platform::current())->toBe('ios')
        ->and(Platform::isIos())->toBeTrue()
        ->and(Platform::isAndroid())->toBeFalse();

    Platform::set('android');
    putenv('NATIVEPHP_PLATFORM');

    expect(Platform::current())->toBe('android');
});
