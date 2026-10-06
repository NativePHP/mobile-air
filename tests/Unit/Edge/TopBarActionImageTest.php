<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * The opt-in `image` prop on top-bar actions: a remote image (e.g. the
 * signed-in user's avatar) drawn as a small circle in place of the icon,
 * which still shows while the image loads or if it fails. Unset keeps
 * today's icon-only behavior.
 */
it('serializes image on a top-bar action', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'profile', 'icon' => 'person', 'image' => 'https://example.com/a.jpg']);

    $props = $action->toArray(new CallbackRegistry)['props'];

    expect($props['image'])->toBe('https://example.com/a.jpg')
        ->and($props['icon'])->not->toBeEmpty();
});

it('omits image when the attribute is not set', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'profile', 'icon' => 'person']);

    expect($action->toArray(new CallbackRegistry)['props'])->not->toHaveKey('image');
});

it('builds an image action through the NavAction fluent API, keeping the icon fallback', function () {
    $props = NavAction::make('profile')->icon('person')->image('https://example.com/a.jpg')->url('/profile')
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props['image'])->toBe('https://example.com/a.jpg')
        ->and($props)->toHaveKey('icon')
        ->and($props['url'])->toBe('/profile');
});

it('treats a null or blank image as no image', function (?string $url) {
    $props = NavAction::make('profile')->icon('person')->image($url)
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('image');
})->with([null, '', '   ']);

it('lets a later image() call clear an earlier one', function () {
    $props = NavAction::make('profile')->icon('person')->image('https://example.com/a.jpg')->image(null)
        ->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props)->not->toHaveKey('image');
});
