<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * The opt-in `subtitle` prop on top-bar actions: a second, smaller line
 * under a menu row's label, such as an account's handle under its name.
 * Unset keeps today's single-line rows.
 */
it('serializes subtitle on a top-bar action', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'me', 'label' => 'Simon', 'subtitle' => '@simon-hamp']);

    expect($action->toArray(new CallbackRegistry)['props'])->toMatchArray([
        'label' => 'Simon',
        'subtitle' => '@simon-hamp',
    ]);
});

it('omits subtitle when the attribute is not set', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'me', 'label' => 'Simon']);

    expect($action->toArray(new CallbackRegistry)['props'])->not->toHaveKey('subtitle');
});

it('builds a subtitled row through the NavAction fluent API', function () {
    $props = NavAction::make('me')->label('Simon')->icon('person')->subtitle('@simon-hamp')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->toMatchArray([
        'label' => 'Simon',
        'subtitle' => '@simon-hamp',
    ])->and($props)->toHaveKey('icon');
});

it('treats a null or empty subtitle as no subtitle', function (?string $subtitle) {
    $props = NavAction::make('me')->label('Simon')->subtitle($subtitle)
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('subtitle');
})->with([null, '']);

it('lets a later subtitle() call clear an earlier one', function () {
    $props = NavAction::make('me')->label('Simon')->subtitle('@simon-hamp')->subtitle(null)
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('subtitle');
});

it('carries a subtitle on a sub-item of a menu', function () {
    $menu = NavAction::make('post-as')->icon('person')->items([
        NavAction::make('me')->label('Simon')->subtitle('@simon-hamp'),
    ])->toElement()->toArray(new CallbackRegistry);

    expect($menu['children'][0]['props']['subtitle'])->toBe('@simon-hamp');
});
