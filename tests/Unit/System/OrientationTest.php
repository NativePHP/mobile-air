<?php

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Events\System\OrientationChanged;
use Native\Mobile\System;

/**
 * Reactive orientation: the query side (System cache) + the event that keeps
 * it fresh, mirroring the AppearanceChanged pattern.
 */
it('marks OrientationChanged for global dispatch', function () {
    expect(is_subclass_of(OrientationChanged::class, BroadcastsGlobally::class))->toBeTrue();
});

it('caches orientation and answers isPortrait/isLandscape off it', function () {
    System::rememberOrientation('landscape');
    $sys = new System;
    expect($sys->orientation())->toBe('landscape');
    expect($sys->isLandscape())->toBeTrue();
    expect($sys->isPortrait())->toBeFalse();

    System::rememberOrientation('portrait');
    expect($sys->orientation())->toBe('portrait');
    expect($sys->isLandscape())->toBeFalse();
});

it('ignores a bogus orientation value', function () {
    System::rememberOrientation('landscape');
    System::rememberOrientation('diagonal'); // not portrait/landscape → no change
    expect((new System)->orientation())->toBe('landscape');
});

it('updates the orientation cache through the service provider listener', function () {
    System::rememberOrientation('portrait');

    OrientationChanged::dispatch('landscape');

    expect((new System)->orientation())->toBe('landscape');
});

it('rebuilds a marked event from its native payload', function () {
    $comp = new class extends NativeComponent {};
    $build = new ReflectionMethod(NativeComponent::class, 'buildEventInstance');
    $build->setAccessible(true);

    $ev = $build->invoke($comp, OrientationChanged::class, ['orientation' => 'landscape']);

    expect($ev)->toBeInstanceOf(OrientationChanged::class);
    expect($ev->orientation)->toBe('landscape');
});
