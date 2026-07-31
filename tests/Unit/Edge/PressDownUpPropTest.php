<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Column;

it('carries onPressDown / onPressUp as props with registered callback ids', function () {
    $registry = new CallbackRegistry;

    $props = Column::make()
        ->onPressDown('startLeft')
        ->onPressUp('stopLeft')
        ->getResolvedProps($registry);

    // Press-down/up travel in the props dict (not dedicated node fields)
    // and reuse the PRESS wire event, so no binary wire-format change.
    expect($props)->toHaveKey('on_press_down');
    expect($props)->toHaveKey('on_press_up');
    expect($props['on_press_down'])->toBeInt()->toBeGreaterThan(0);
    expect($props['on_press_up'])->toBeInt()->toBeGreaterThan(0);
    expect($props['on_press_down'])->not->toBe($props['on_press_up']);

    // Each id resolves back to its own handler method.
    expect($registry->resolve($props['on_press_down'])['method'])->toBe('startLeft');
    expect($registry->resolve($props['on_press_up'])['method'])->toBe('stopLeft');
});

it('registers press-down without press-up (and vice versa)', function () {
    $registry = new CallbackRegistry;

    $props = Column::make()->onPressDown('onlyDown')->getResolvedProps($registry);
    expect($props)->toHaveKey('on_press_down');
    expect($props)->not->toHaveKey('on_press_up');

    $props = Column::make()->onPressUp('onlyUp')->getResolvedProps(new CallbackRegistry);
    expect($props)->toHaveKey('on_press_up');
    expect($props)->not->toHaveKey('on_press_down');
});

it('omits both props when no handlers are set', function () {
    $props = Column::make()->getResolvedProps(new CallbackRegistry);

    expect($props)->not->toHaveKey('on_press_down');
    expect($props)->not->toHaveKey('on_press_up');
});
