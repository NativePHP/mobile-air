<?php

use Native\Mobile\System;
use Native\Mobile\Testing\FakeBridge;

afterEach(function () {
    putenv('NATIVEPHP_OS_VERSION');
    putenv('JUMP_BRIDGE_PORT');
    System::rememberOsVersion(null);
    FakeBridge::disable();
});

it('takes the OS version from the env the shell sets, without asking the device', function () {
    putenv('NATIVEPHP_OS_VERSION=26.1');
    $bridge = FakeBridge::enable();

    expect((new System)->osVersion())->toBe('26.1');

    $bridge->assertNotCalled('Device.GetInfo');
});

it('asks the device once under Jump, where nothing sets the env', function () {
    putenv('JUMP_BRIDGE_PORT=9999');
    $bridge = FakeBridge::enable()->respondTo('Device.GetInfo', [
        'info' => json_encode(['platform' => 'ios', 'osVersion' => '18.5']),
    ]);

    $system = new System;

    expect($system->osVersion())->toBe('18.5')
        ->and($system->osVersion())->toBe('18.5')
        ->and($bridge->callsTo('Device.GetInfo'))->toHaveCount(1);
});

it('is null off the device and outside Jump, without a round-trip', function () {
    $bridge = FakeBridge::enable();

    expect((new System)->osVersion())->toBeNull();

    $bridge->assertNotCalled('Device.GetInfo');
});

it('lets a test pretend to be on an OS version', function () {
    System::rememberOsVersion('26.0');

    expect((new System)->osVersion())->toBe('26.0');
});
