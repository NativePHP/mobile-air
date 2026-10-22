<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Elements\Icon as IconElement;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Icon\Icon;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Platform;
use Tests\Fixtures\Edge\FixtureAndroidIcon;
use Tests\Fixtures\Edge\FixtureIosIcon;

/**
 * `Icon::custom('<name>')` is just the string `custom:<name>`: it rides the
 * existing icon slots, per-platform overrides keep working, and it never
 * picks up a `material_variant`.
 */
afterEach(function () {
    Platform::set(null);
});

it('builds the custom icon wire string', function () {
    expect(Icon::custom('git-commit-vertical'))->toBe('custom:git-commit-vertical')
        ->and(Icon::custom('bell'))->toBe('custom:bell')
        ->and(Icon::custom('trash-2'))->toBe('custom:trash-2');
});

it('rejects names that cannot be icon file names', function (string $name) {
    Icon::custom($name);
})->throws(InvalidArgumentException::class)->with([
    'empty' => '',
    'uppercase' => 'Bell',
    'underscore' => 'git_commit',
    'leading hyphen' => '-bell',
    'trailing hyphen' => 'bell-',
    'double hyphen' => 'git--commit',
    'space' => 'git commit',
    'extension' => 'bell.svg',
    'path' => 'icons/bell',
    'prefixed' => 'custom:bell',
]);

it('recognises custom icon strings', function () {
    expect(Icon::isCustom('custom:bell'))->toBeTrue()
        ->and(Icon::isCustom('bell'))->toBeFalse()
        ->and(Icon::isCustom(null))->toBeFalse();
});

it('resolves custom icons through every slot combination', function (?string $platform, ?string $name, mixed $ios, mixed $android, ?string $icon, ?string $variant) {
    Platform::set($platform);

    expect(IconResolver::resolve($name, $ios, $android))->toBe(['icon' => $icon, 'variant' => $variant]);
})->with([
    'shared custom on android' => ['android', 'custom:bell', null, null, 'custom:bell', null],
    'shared custom on ios' => ['ios', 'custom:bell', null, null, 'custom:bell', null],
    'shared custom, unknown platform' => [null, 'custom:bell', null, null, 'custom:bell', null],
    'custom on ios, material enum on android (android)' => ['android', 'custom:bell', null, FixtureAndroidIcon::Inbox, 'inbox', 'outlined'],
    'custom on ios, material enum on android (ios)' => ['ios', 'custom:bell', null, FixtureAndroidIcon::Inbox, 'custom:bell', null],
    'custom android override string' => ['android', 'bell', null, 'custom:bell', 'custom:bell', null],
    'custom ios override string' => ['ios', 'bell', 'custom:bell', null, 'custom:bell', null],
    'custom ios override, android falls back' => ['android', 'bell', 'custom:bell', null, 'bell', null],
    'custom value on an android enum case' => ['android', 'bell', null, FixtureAndroidIcon::CustomBell, 'custom:bell', null],
    'ios enum override over shared custom' => ['ios', 'custom:bell', FixtureIosIcon::Star, null, 'star.fill', null],
]);

it('keeps material variants for non-custom android enums', function () {
    Platform::set('android');

    expect(IconResolver::resolve('bell', null, FixtureAndroidIcon::Inbox))->toBe(['icon' => 'inbox', 'variant' => 'outlined']);
});

it('serializes custom icons on tabs and nav actions without a variant', function () {
    Platform::set('android');

    $tab = Tab::link('Bell', '/bell', icon: 'bell', android: Icon::custom('bell'))->toElement()->toArray(new CallbackRegistry)['props'];
    $action = NavAction::make('bell')->icon('bell', android: FixtureAndroidIcon::CustomBell)->toElement()->toArray(new CallbackRegistry)['props'];

    expect($tab['icon'])->toBe('custom:bell')->and($tab)->not->toHaveKey('material_variant')
        ->and($action['icon'])->toBe('custom:bell')->and($action)->not->toHaveKey('material_variant');
});

it('draws the material glyph on android when only ios is custom', function () {
    Platform::set('android');

    $props = Tab::link('Bell', '/bell', icon: Icon::custom('bell'), android: FixtureAndroidIcon::Inbox)->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props['icon'])->toBe('inbox')->and($props['material_variant'])->toBe('outlined');
});

it('serializes a custom <icon> element without a variant', function () {
    Platform::set('android');

    $props = IconElement::make('bell', android: FixtureAndroidIcon::CustomBell)->toArray(new CallbackRegistry)['props'];

    expect($props['name'])->toBe('custom:bell')->and($props)->not->toHaveKey('material_variant');
});

it('drops an explicit material variant from custom chrome icons', function (string $element) {
    Platform::set('android');

    $node = $element::make();
    $node->applyAttributes([
        'id' => 'bell',
        'icon' => FixtureAndroidIcon::Inbox,
        'android-icon' => Icon::custom('bell'),
        'material-variant' => 'outlined',
    ]);

    $props = $node->toArray(new CallbackRegistry)['props'];

    expect($props['icon'])->toBe('custom:bell')->and($props)->not->toHaveKey('material_variant');
})->with([TopBarAction::class, BottomNavItem::class]);

it('keeps an explicit material variant when the resolved icon is not custom', function () {
    Platform::set('android');

    $node = TopBarAction::make();
    $node->applyAttributes(['id' => 'bell', 'icon' => 'custom:bell', 'android-icon' => 'notifications', 'material-variant' => 'outlined']);

    $props = $node->toArray(new CallbackRegistry)['props'];

    expect($props['icon'])->toBe('notifications')->and($props['material_variant'])->toBe('outlined');
});
