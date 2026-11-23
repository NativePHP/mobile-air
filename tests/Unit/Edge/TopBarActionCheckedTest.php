<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * The opt-in `checked` prop on top-bar actions: a tick beside a menu row,
 * for the option a menu has picked. Unset keeps today's rows.
 */
it('serializes checked on a top-bar action as a bool', function (mixed $value, bool $expected) {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'me', 'label' => 'Simon', 'checked' => $value]);

    expect($action->toArray(new CallbackRegistry)['props']['checked'])->toBe($expected);
})->with([
    [true, true],
    [false, false],
    ['1', true],
    [1, true],
    [0, false],
]);

it('omits checked when the attribute is not set', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'me', 'label' => 'Simon']);

    expect($action->toArray(new CallbackRegistry)['props'])->not->toHaveKey('checked');
});

it('builds a checked row through the NavAction fluent API', function () {
    $props = NavAction::make('me')->label('Simon')->icon('person')->checked()
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props['checked'])->toBeTrue()
        ->and($props)->toHaveKey('icon');
});

it('leaves checked off for checked(false) and when never called', function () {
    $off = NavAction::make('me')->label('Simon')->checked(false)
        ->toElement()->toArray(new CallbackRegistry)['props'];
    $unset = NavAction::make('me')->label('Simon')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($off)->not->toHaveKey('checked')
        ->and($unset)->not->toHaveKey('checked');
});

it('marks only the picked row of a menu as checked', function () {
    $postAs = 'acme';

    $menu = NavAction::make('post-as')->icon('person')->items([
        NavAction::make('me')->label('Simon')->checked($postAs === 'me'),
        NavAction::make('acme')->label('Acme')->checked($postAs === 'acme'),
    ])->toElement()->toArray(new CallbackRegistry);

    expect($menu['children'][0]['props'])->not->toHaveKey('checked')
        ->and($menu['children'][1]['props']['checked'])->toBeTrue();
});

it('ignores checked on a divider', function () {
    $props = NavAction::divider()->checked()
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('checked')
        ->and($props['divider'])->toBeTrue();
});
