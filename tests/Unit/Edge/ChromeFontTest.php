<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;

/**
 * Chrome fonts: a layout-wide `NativeLayout::$font` flows to both bars via
 * `defaultFont()` (set-if-unset), losing to any explicit `->font()` on the
 * bar or per-screen `NavBarOptions`/`TabBarOptions`. Serialized as `fontName`
 * in toRootProps()/toElement() attrs → `font_name` wire prop on the chrome
 * sentinels, resolved natively through the plugin font seam.
 */
it('applies the layout default font only when the bar has none', function () {
    $bar = NavBar::make()->title('Home');
    $bar->defaultFont('Lobster-Regular');

    expect($bar->toRootProps()['fontName'])->toBe('Lobster-Regular');
});

it('lets an explicit bar font win over the layout default', function () {
    $bar = NavBar::make()->title('Home')->font('Inter-Bold');
    $bar->defaultFont('Lobster-Regular');

    expect($bar->toRootProps()['fontName'])->toBe('Inter-Bold');
});

it('lets per-screen NavBarOptions override the font', function () {
    $bar = NavBar::make()->title('Home');
    $bar->mergeOptions(NavBarOptions::make()->font('RockSalt-Regular'));
    $bar->defaultFont('Lobster-Regular');

    expect($bar->toRootProps()['fontName'])->toBe('RockSalt-Regular');
});

it('omits fontName entirely when nothing sets a font', function () {
    expect(NavBar::make()->title('Home')->toRootProps())->not->toHaveKey('fontName');
    expect(TabBar::make()->toRootProps())->not->toHaveKey('fontName');
});

it('flows the font through NavBar::toElement to the top_bar font_name prop', function () {
    $bar = NavBar::make()->title('Home')->font('Inter-Bold');

    $props = $bar->toElement()->toArray(new CallbackRegistry)['props'];

    expect($props['font_name'])->toBe('Inter-Bold');
});

it('serializes the tab bar font in both root props and the bottom_nav element', function () {
    $bar = TabBar::make()->font('Lobster-Regular');

    expect($bar->toRootProps()['fontName'])->toBe('Lobster-Regular');

    $props = $bar->toElement()->toArray(new CallbackRegistry)['props'];
    expect($props['font_name'])->toBe('Lobster-Regular');
});

it('supports defaultFont on the tab bar with explicit font winning', function () {
    $a = TabBar::make();
    $a->defaultFont('Lobster-Regular');
    expect($a->toRootProps()['fontName'])->toBe('Lobster-Regular');

    $b = TabBar::make()->font('Inter-Bold');
    $b->defaultFont('Lobster-Regular');
    expect($b->toRootProps()['fontName'])->toBe('Inter-Bold');
});

it('exposes a font on TabBarOptions', function () {
    expect(TabBarOptions::make()->font('Inter-Bold')->font)->toBe('Inter-Bold');
});
