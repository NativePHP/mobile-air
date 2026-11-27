<?php

use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeScreen;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\KeepOnPushTabsLayout;
use Tests\Fixtures\Edge\KeepTabBarScreen;

/**
 * Whether the tab bar stays on screens pushed inside a tab. It hides by
 * default on both platforms, and only `hideOnPush(false)` is sent, since the
 * renderers treat a missing `hide_on_push` as true. They also read the
 * per-screen `show_tab_bar`.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('sends nothing by default, which the renderers read as hide on push', function () {
    expect(TabBar::make()->toRootProps())->not->toHaveKey('hideOnPush');

    $props = Native::test(ChromeScreen::class, layout: ChromeTabsLayout::class)->tree()['props'];

    expect($props)->not->toHaveKey('hide_on_push')
        ->and($props)->not->toHaveKey('show_tab_bar');
});

it('serializes hideOnPush(false)', function () {
    expect(TabBar::make()->hideOnPush(false)->toRootProps())->toBe(['hideOnPush' => false])
        ->and(TabBar::make()->hideOnPush(false)->toElement()->getRawProps()['hide_on_push'])->toBeFalse();
});

it('sends nothing for hideOnPush(true), the same as the default', function () {
    expect(TabBar::make()->hideOnPush(true)->toRootProps())->toBe([])
        ->and(TabBar::make()->hideOnPush()->toRootProps())->toBe([])
        ->and(TabBar::make()->hideOnPush(true)->toElement()->getRawProps())->not->toHaveKey('hide_on_push');
});

it('publishes hide_on_push on the native root tabs', function () {
    $tree = Native::test(ChromeScreen::class, layout: KeepOnPushTabsLayout::class)->tree();

    expect($tree['type'])->toBe('native_root_tabs')
        ->and($tree['props']['hide_on_push'])->toBeFalse();
});

it('reads hide-on-push from an inline bottom-nav', function (mixed $value, bool $expected) {
    $nav = BottomNav::make();
    $nav->applyAttributes(['hide-on-push' => $value]);

    expect($nav->getRawProps()['hide_on_push'])->toBe($expected)
        ->and(TabBar::fromElement($nav)->toRootProps()['hideOnPush'] ?? true)->toBe($expected);
})->with([
    'string false' => ['false', false],
    'bound false' => [false, false],
    'string true' => ['true', true],
]);

it('keeps the bar on a screen that sets tabBarOptions()->hidden(false)', function () {
    $props = Native::test(KeepTabBarScreen::class, layout: ChromeTabsLayout::class)->tree()['props'];

    expect($props['show_tab_bar'])->toBeTrue()
        ->and($props)->not->toHaveKey('hide_tab_bar');
});
