<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Column;

it('carries onDoubleTap as the on_double_tap prop with a registered callback id', function () {
    $registry = new CallbackRegistry;

    $props = Column::make()
        ->onDoubleTap('handleDouble')
        ->getResolvedProps($registry);

    // Double-tap travels in the props dict (not a dedicated node field),
    // so no binary wire-format change is needed.
    expect($props)->toHaveKey('on_double_tap');
    expect($props['on_double_tap'])->toBeInt()->toBeGreaterThan(0);

    // And the id resolves back to the handler method.
    $resolved = $registry->resolve($props['on_double_tap']);
    expect($resolved['method'])->toBe('handleDouble');
});

it('omits on_double_tap when no double-tap handler is set', function () {
    $props = Column::make()->getResolvedProps(new CallbackRegistry);

    expect($props)->not->toHaveKey('on_double_tap');
});
