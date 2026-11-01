<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\TopBarAction;

/**
 * The opt-in `show-label` prop: renders the action's label as visible
 * text ("Edit"-style), or icon + text when an icon is also set.
 */
it('serializes show-label on a top-bar action', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'new', 'label' => 'New round', 'icon' => 'plus', 'show-label' => true]);

    $props = $action->toArray(new CallbackRegistry)['props'];

    expect($props['show_label'])->toBeTrue()
        ->and($props['label'])->toBe('New round');
});

it('accepts the snake_case spelling too', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'new', 'label' => 'New', 'show_label' => '1']);

    expect($action->toArray(new CallbackRegistry)['props']['show_label'])->toBeTrue();
});

it('omits show_label when the attribute is not set', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'new', 'label' => 'New', 'icon' => 'plus']);

    expect($action->toArray(new CallbackRegistry)['props'])->not->toHaveKey('show_label');
});
