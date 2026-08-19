<?php

use Native\Mobile\Attributes\On;
use Native\Mobile\Device;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Events\Device\ThermalStateChanged;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\ThermalState;

beforeEach(fn () => Device::forgetThermalState());

afterEach(function () {
    Device::forgetThermalState();
    FakeBridge::disable();
});

describe('ThermalState helpers', function () {
    it('treats Normal as not warm, hot, or critical', function () {
        expect(ThermalState::Normal->isWarm())->toBeFalse()
            ->and(ThermalState::Normal->isHot())->toBeFalse()
            ->and(ThermalState::Normal->isCritical())->toBeFalse();
    });

    it('treats Warm as warm only', function () {
        expect(ThermalState::Warm->isWarm())->toBeTrue()
            ->and(ThermalState::Warm->isHot())->toBeFalse()
            ->and(ThermalState::Warm->isCritical())->toBeFalse();
    });

    it('treats Hot as warm and hot', function () {
        expect(ThermalState::Hot->isWarm())->toBeTrue()
            ->and(ThermalState::Hot->isHot())->toBeTrue()
            ->and(ThermalState::Hot->isCritical())->toBeFalse();
    });

    it('treats Critical as warm, hot, and critical', function () {
        expect(ThermalState::Critical->isWarm())->toBeTrue()
            ->and(ThermalState::Critical->isHot())->toBeTrue()
            ->and(ThermalState::Critical->isCritical())->toBeTrue();
    });

    it('compares direction with isWarmerThan and isCoolerThan', function () {
        expect(ThermalState::Hot->isWarmerThan(ThermalState::Warm))->toBeTrue()
            ->and(ThermalState::Hot->isCoolerThan(ThermalState::Warm))->toBeFalse()
            ->and(ThermalState::Normal->isCoolerThan(ThermalState::Critical))->toBeTrue()
            ->and(ThermalState::Warm->isWarmerThan(ThermalState::Warm))->toBeFalse();
    });
});

describe('Device::thermalState() cache', function () {
    it('returns the cached state without probing the bridge', function () {
        Device::rememberThermalState(ThermalState::Hot);
        $bridge = FakeBridge::enable()->respondTo('Device.GetThermalState', ['state' => 'normal']);

        expect((new Device)->thermalState())->toBe(ThermalState::Hot)
            ->and($bridge->calls)->toBeEmpty();
    });

    it('probes the bridge on a cold read and caches the result', function () {
        $bridge = FakeBridge::enable()->respondTo('Device.GetThermalState', ['state' => 'hot']);

        $device = new Device;
        expect($device->thermalState())->toBe(ThermalState::Hot)
            ->and($device->thermalState())->toBe(ThermalState::Hot)
            ->and($bridge->callsTo('Device.GetThermalState'))->toHaveCount(1);
    });

    it('returns Normal when the bridge is present but has no thermal data', function () {
        FakeBridge::enable();

        expect((new Device)->thermalState())->toBe(ThermalState::Normal);
    });

    it('returns Normal for an unrecognized state string', function () {
        FakeBridge::enable()->respondTo('Device.GetThermalState', ['state' => 'scorching']);

        expect((new Device)->thermalState())->toBe(ThermalState::Normal);
    });
});

describe('ThermalStateChanged', function () {
    it('marks ThermalStateChanged for global dispatch', function () {
        expect(is_subclass_of(ThermalStateChanged::class, BroadcastsGlobally::class))->toBeTrue();
    });

    it('rebuilds from its native payload and reports warming vs cooling', function () {
        $comp = new class extends NativeComponent {};
        $build = new ReflectionMethod(NativeComponent::class, 'buildEventInstance');
        $build->setAccessible(true);

        $warming = $build->invoke($comp, ThermalStateChanged::class, [
            'state' => 'hot',
            'previous' => 'warm',
        ]);

        expect($warming)->toBeInstanceOf(ThermalStateChanged::class)
            ->and($warming->state)->toBe(ThermalState::Hot)
            ->and($warming->previous)->toBe(ThermalState::Warm)
            ->and($warming->isWarming())->toBeTrue()
            ->and($warming->isCooling())->toBeFalse();

        $cooling = $build->invoke($comp, ThermalStateChanged::class, [
            'state' => 'normal',
            'previous' => 'critical',
        ]);

        expect($cooling->isWarming())->toBeFalse()
            ->and($cooling->isCooling())->toBeTrue();
    });

    it('dispatches marked events globally but leaves unmarked ones to the component', function () {
        $comp = new class extends NativeComponent {};
        $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchGloballyIfMarked');
        $dispatch->setAccessible(true);

        $seen = [];
        Event::listen(ThermalStateChanged::class, function ($e) use (&$seen) {
            $seen[] = $e->state;
        });
        Event::listen(ButtonPressed::class, function () use (&$seen) {
            $seen[] = 'button';
        });

        $dispatch->invoke($comp, ThermalStateChanged::class, [
            'state' => 'hot',
            'previous' => 'warm',
        ]);
        $dispatch->invoke($comp, ButtonPressed::class, ['index' => 0, 'label' => 'OK']);

        expect($seen)->toBe([ThermalState::Hot]);
    });

    it('coerces backed enums onto #[On] handler parameters', function () {
        $comp = new class extends NativeComponent
        {
            public ?ThermalState $state = null;

            public ?ThermalState $previous = null;

            #[On(ThermalStateChanged::class)]
            public function onThermal(ThermalState $state, ThermalState $previous): void
            {
                $this->state = $state;
                $this->previous = $previous;
            }
        };

        $register = new ReflectionMethod(NativeComponent::class, 'registerNativeEventListeners');
        $register->setAccessible(true);
        $register->invoke($comp);

        $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchNativeEvent');
        $dispatch->setAccessible(true);
        $dispatch->invoke($comp, [
            'event' => ThermalStateChanged::class,
            'payload' => ['state' => 'critical', 'previous' => 'hot'],
        ]);

        expect($comp->state)->toBe(ThermalState::Critical)
            ->and($comp->previous)->toBe(ThermalState::Hot);
    });

    it('keeps Device::thermalState() in sync when ThermalStateChanged is dispatched globally', function () {
        $comp = new class extends NativeComponent {};
        $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchGloballyIfMarked');
        $dispatch->setAccessible(true);

        $dispatch->invoke($comp, ThermalStateChanged::class, [
            'state' => 'hot',
            'previous' => 'normal',
        ]);

        expect((new Device)->thermalState())->toBe(ThermalState::Hot);
    });
});
