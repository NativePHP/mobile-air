<?php

use Native\Mobile\Device;
use Native\Mobile\Testing\FakeBridge;

afterEach(fn () => FakeBridge::disable());

it('reads the phone timezone from the device info', function () {
    $bridge = FakeBridge::enable()->respondTo('Device.GetInfo', [
        'info' => json_encode(['platform' => 'ios', 'timezone' => 'Europe/Lisbon']),
    ]);

    expect((new Device)->getTimezone())->toBe('Europe/Lisbon')
        ->and($bridge->callsTo('Device.GetInfo'))->toHaveCount(1);
});

it('asks the device again on every call', function () {
    $bridge = FakeBridge::enable()->respondTo('Device.GetInfo', [
        'info' => json_encode(['timezone' => 'Europe/Lisbon']),
    ]);

    $device = new Device;
    $device->getTimezone();

    $bridge->respondTo('Device.GetInfo', ['info' => json_encode(['timezone' => 'Asia/Tokyo'])]);

    expect($device->getTimezone())->toBe('Asia/Tokyo')
        ->and($bridge->callsTo('Device.GetInfo'))->toHaveCount(2);
});

it('returns null when the device info has no timezone, as older native builds send it', function () {
    FakeBridge::enable()->respondTo('Device.GetInfo', [
        'info' => json_encode(['platform' => 'android', 'language' => 'en-US']),
    ]);

    expect((new Device)->getTimezone())->toBeNull();
});

it('returns null when the device sends no info', function (mixed $response) {
    FakeBridge::enable()->respondTo('Device.GetInfo', $response);

    expect((new Device)->getTimezone())->toBeNull();
})->with([
    'no answer' => [null],
    'an error' => [['info' => json_encode(['error' => 'Failed to convert JSON data to string'])]],
    'an empty timezone' => [['info' => json_encode(['timezone' => ''])]],
    'a timezone that is not a string' => [['info' => json_encode(['timezone' => 3600])]],
]);
