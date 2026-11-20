<?php

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Events\System\AppearanceChanged;
use Native\Mobile\Facades\System as SystemFacade;
use Native\Mobile\System;
use Native\Mobile\Testing\FakeBridge;

afterEach(fn () => FakeBridge::disable());

/**
 * Reactive appearance: the query side (System cache) + the global-dispatch
 * plumbing that lets a native event reach app-wide listeners, not just the
 * active component's #[On] handlers.
 */
it('marks AppearanceChanged for global dispatch', function () {
    expect(is_subclass_of(AppearanceChanged::class, BroadcastsGlobally::class))->toBeTrue();
});

it('caches appearance and answers isDark/isLight off it', function () {
    System::rememberAppearance('dark');
    $sys = new System;
    expect($sys->appearance())->toBe('dark');
    expect($sys->isDarkMode())->toBeTrue();
    expect($sys->isLightMode())->toBeFalse();

    System::rememberAppearance('light');
    expect($sys->appearance())->toBe('light');
    expect($sys->isDarkMode())->toBeFalse();
});

it('ignores a bogus appearance value', function () {
    System::rememberAppearance('dark');
    System::rememberAppearance('chartreuse'); // not light/dark → no change
    expect((new System)->appearance())->toBe('dark');
});

it('sends the chosen appearance to the device', function (string $mode) {
    $bridge = FakeBridge::enable();

    SystemFacade::setAppearance($mode);

    expect($bridge->callsTo('System.SetAppearance'))->toHaveCount(1)
        ->and($bridge->callsTo('System.SetAppearance')[0]['params'])->toBe(['mode' => $mode]);
})->with(['system', 'light', 'dark']);

it('rejects an appearance the device does not know without calling it', function (string $mode) {
    $bridge = FakeBridge::enable();

    expect(fn () => (new System)->setAppearance($mode))->toThrow(InvalidArgumentException::class);

    $bridge->assertNotCalled('System.SetAppearance');
})->with(['auto', 'Dark', '']);

it('leaves the appearance cache to the AppearanceChanged event', function () {
    FakeBridge::enable();
    System::rememberAppearance('light');

    (new System)->setAppearance('dark');

    // The device answers with AppearanceChanged once its windows have
    // actually flipped; until then theme() must keep matching what is drawn.
    expect((new System)->appearance())->toBe('light');

    AppearanceChanged::dispatch('dark');

    expect((new System)->appearance())->toBe('dark');
});

it('updates the appearance cache through the service provider listener', function () {
    System::rememberAppearance('light');

    AppearanceChanged::dispatch('dark');

    expect((new System)->appearance())->toBe('dark');
});

it('rebuilds a marked event from its native payload', function () {
    $comp = new class extends NativeComponent {};
    $build = new ReflectionMethod(NativeComponent::class, 'buildEventInstance');
    $build->setAccessible(true);

    $ev = $build->invoke($comp, AppearanceChanged::class, ['mode' => 'dark']);

    expect($ev)->toBeInstanceOf(AppearanceChanged::class);
    expect($ev->mode)->toBe('dark');
});

it('dispatches marked events globally but leaves unmarked ones to the component', function () {
    $comp = new class extends NativeComponent {};
    $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchGloballyIfMarked');
    $dispatch->setAccessible(true);

    $seen = [];
    Event::listen(AppearanceChanged::class, function ($e) use (&$seen) {
        $seen[] = $e->mode;
    });
    Event::listen(ButtonPressed::class, function () use (&$seen) {
        $seen[] = 'button';
    });

    $dispatch->invoke($comp, AppearanceChanged::class, ['mode' => 'dark']);
    // BroadcastsGlobally not implemented → must NOT auto-dispatch globally.
    $dispatch->invoke($comp, ButtonPressed::class, ['index' => 0, 'label' => 'OK']);

    expect($seen)->toBe(['dark']);
});
