<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * The opt-in `disabled` prop on top-bar actions: greys the button and
 * swallows taps on both platforms. Unset keeps today's behavior.
 */
it('serializes disabled on a top-bar action', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'undo', 'icon' => 'arrow.uturn.backward', 'disabled' => true]);

    expect($action->toArray(new CallbackRegistry)['props']['disabled'])->toBeTrue();
});

it('coerces disabled to a boolean', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'undo', 'disabled' => '1']);

    expect($action->toArray(new CallbackRegistry)['props']['disabled'])->toBeTrue();
});

it('omits disabled when the attribute is not set', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'undo', 'icon' => 'clock']);

    expect($action->toArray(new CallbackRegistry)['props'])->not->toHaveKey('disabled');
});

it('keeps an explicit disabled=false serialized as false', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'undo', 'disabled' => false]);

    expect($action->toArray(new CallbackRegistry)['props']['disabled'])->toBeFalse();
});

it('builds a disabled action through the NavAction fluent API', function () {
    $props = NavAction::make('undo')->icon('undo')->disabled()->press('undo')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props['disabled'])->toBeTrue();
});

it('leaves NavAction disabled(false) out of the wire props', function () {
    $props = NavAction::make('undo')->icon('undo')->disabled(false)->press('undo')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('disabled');
});
