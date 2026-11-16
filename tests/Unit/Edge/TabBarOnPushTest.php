<?php

use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeScreen;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\KeepOnPushTabsLayout;
use Tests\Fixtures\Edge\KeepTabBarScreen;

/**
 * Whether the tab bar stays on screens pushed inside a tab. Unset leaves the
 * renderers' default (both hide it); `hideOnPush(false)` keeps it. The
 * renderers read `hide_on_push` and the per-screen `show_tab_bar`.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('leaves hide-on-push unset by default so platforms keep their behaviour', function () {
    expect(TabBar::make()->toRootProps())->not->toHaveKey('hideOnPush');

    $props = Native::test(ChromeScreen::class, layout: ChromeTabsLayout::class)->tree()['props'];

    expect($props)->not->toHaveKey('hide_on_push')
        ->and($props)->not->toHaveKey('show_tab_bar');
});

it('serializes an explicit hideOnPush choice', function (bool $hide) {
    expect(TabBar::make()->hideOnPush($hide)->toRootProps())->toBe(['hideOnPush' => $hide])
        ->and(TabBar::make()->hideOnPush($hide)->toElement()->getRawProps()['hide_on_push'])->toBe($hide);
})->with([true, false]);

it('defaults hideOnPush() to hiding', function () {
    expect(TabBar::make()->hideOnPush()->toRootProps())->toBe(['hideOnPush' => true]);
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
        ->and(TabBar::fromElement($nav)->toRootProps()['hideOnPush'])->toBe($expected);
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
