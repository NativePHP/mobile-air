<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * The opt-in `image_shape` prop on top-bar actions: `circle` (the
 * renderers' default) or `rounded`, the shape a menu row's `image` is cut
 * to. Unset keeps today's circle.
 */
it('serializes image_shape on a top-bar action', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'acme', 'image' => 'https://example.com/logo.png', 'image_shape' => 'rounded']);

    expect($action->toArray(new CallbackRegistry)['props']['image_shape'])->toBe('rounded');
});

it('accepts image-shape as a Blade attribute', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'acme', 'image' => 'https://example.com/logo.png', 'image-shape' => 'rounded']);

    expect($action->toArray(new CallbackRegistry)['props']['image_shape'])->toBe('rounded');
});

it('omits image_shape when the attribute is not set', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'acme', 'image' => 'https://example.com/logo.png']);

    expect($action->toArray(new CallbackRegistry)['props'])->not->toHaveKey('image_shape');
});

it('builds a shaped image through the NavAction fluent API', function (string $shape) {
    $props = NavAction::make('acme')->label('Acme')->icon('person')
        ->image('https://example.com/logo.png')->imageShape($shape)
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->toMatchArray([
        'image' => 'https://example.com/logo.png',
        'image_shape' => $shape,
    ]);
})->with(['circle', 'rounded']);

it('leaves image_shape off when imageShape() is never called', function () {
    $props = NavAction::make('me')->label('Simon')->image('https://example.com/a.jpg')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('image_shape');
});

it('rejects a shape the renderers do not know', function (string $shape) {
    NavAction::make('acme')->imageShape($shape);
})->with(['square', 'Rounded', ''])->throws(InvalidArgumentException::class);

it('carries image_shape on a sub-item of a menu', function () {
    $menu = NavAction::make('post-as')->icon('person')->items([
        NavAction::make('acme')->label('Acme')->image('https://example.com/logo.png')->imageShape('rounded'),
    ])->toElement()->toArray(new CallbackRegistry);

    expect($menu['children'][0]['props']['image_shape'])->toBe('rounded');
});
