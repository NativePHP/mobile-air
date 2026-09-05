<?php

use Native\Mobile\Device;
use Native\Mobile\Events\Device\ThermalStateChanged;
use Native\Mobile\ThermalState;

beforeEach(fn () => Device::forgetThermalState());

afterEach(fn () => Device::forgetThermalState());

it('hydrates ThermalStateChanged from the webview POST payload', function () {
    $seen = null;
    Event::listen(ThermalStateChanged::class, function (ThermalStateChanged $e) use (&$seen) {
        $seen = $e;
    });

    $this->postJson('_native/api/events', [
        'event' => ThermalStateChanged::class,
        'payload' => [
            'state' => 'hot',
            'previous' => 'warm',
        ],
    ])->assertSuccessful()
        ->assertJson(['success' => true]);

    expect($seen)->toBeInstanceOf(ThermalStateChanged::class)
        ->and($seen->state)->toBe(ThermalState::Hot)
        ->and($seen->previous)->toBe(ThermalState::Warm)
        ->and((new Device)->thermalState())->toBe(ThermalState::Hot);
});
