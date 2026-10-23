<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * NavAction::a11yLabel() reaches the wire as `a11y_label`, which the iOS
 * and Android top-bar renderers use as the action's accessible name
 * (falling back to the visible `label`).
 */
it('serializes a NavAction a11yLabel as a11y_label', function () {
    $props = NavAction::make('switch-organization')->icon('building')->a11yLabel('Switch organization')->press('noop')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props['a11y_label'])->toBe('Switch organization')
        ->and($props)->not->toHaveKey('label');
});

it('omits a11y_label when a11yLabel is not set', function () {
    $props = NavAction::make('switch-organization')->icon('building')->press('noop')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('a11y_label');
});
