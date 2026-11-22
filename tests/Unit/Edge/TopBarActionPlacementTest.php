<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * The opt-in `placement="bottom"` prop on top-bar actions: on iPhone
 * (iOS 26+) the action joins the bottom toolbar, beside the system search
 * field. Unset keeps every action in the top bar, as before.
 */
it('serializes placement bottom on a top-bar action', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'compose', 'icon' => 'square.and.pencil', 'placement' => 'bottom']);

    expect($action->toArray(new CallbackRegistry)['props']['placement'])->toBe('bottom');
});

it('omits placement when the attribute is not set', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'compose', 'icon' => 'square.and.pencil']);

    expect($action->toArray(new CallbackRegistry)['props'])->not->toHaveKey('placement');
});

it('keeps an action in the top bar for any other placement', function (mixed $placement) {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'compose', 'placement' => $placement]);

    expect($action->toArray(new CallbackRegistry)['props'])->not->toHaveKey('placement');
})->with(['top', 'Bottom', '', true]);

it('builds a bottom action through the NavAction fluent API', function () {
    $props = NavAction::make('compose')->icon('compose')->placement('bottom')->press('compose')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props['placement'])->toBe('bottom');
});

it('leaves placement out of the wire props when NavAction does not set it', function () {
    $props = NavAction::make('compose')->icon('compose')->press('compose')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('placement');
});
