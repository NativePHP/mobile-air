<?php

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Events\System\AppearanceChanged;
use Native\Mobile\System;

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
    Event::listen(\Native\Mobile\Events\Alert\ButtonPressed::class, function () use (&$seen) {
        $seen[] = 'button';
    });

    $dispatch->invoke($comp, AppearanceChanged::class, ['mode' => 'dark']);
    // BroadcastsGlobally not implemented → must NOT auto-dispatch globally.
    $dispatch->invoke($comp, \Native\Mobile\Events\Alert\ButtonPressed::class, ['index' => 0, 'label' => 'OK']);

    expect($seen)->toBe(['dark']);
});
