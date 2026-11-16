<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeScreen;
use Tests\Fixtures\Edge\RefBottomNavScreen;
use Tests\Fixtures\Edge\RefTabsLayout;

/**
 * Tab items take a `ref` so UI tests can find the tab buttons. The renderers
 * read the `ref` prop: iOS sets it as the tab button's accessibility
 * identifier, Android as the NavigationBarItem's testTag.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

function tabItemsOf(array $tree): array
{
    return array_values(array_filter(
        $tree['children'] ?? [],
        fn (array $child) => $child['type'] === 'bottom_nav_item',
    ));
}

it('carries a builder ref onto the tab item wire node', function () {
    $node = Tab::link('Home', '/', icon: 'home')->ref('tab-home')
        ->toElement()
        ->toArray(new CallbackRegistry);

    expect($node['props']['ref'])->toBe('tab-home')
        ->and($node['ref'])->toBe('tab-home');
});

it('leaves tabs without a ref unnamed', function () {
    $node = Tab::link('Home', '/')->toElement()->toArray(new CallbackRegistry);

    expect($node['props'])->not->toHaveKey('ref')
        ->and($node)->not->toHaveKey('ref');
});

it('accepts ref through applyAttributes and ignores an empty one', function () {
    $named = BottomNavItem::make();
    $named->applyAttributes(['id' => 'home', 'label' => 'Home', 'ref' => 'tab-home']);

    $blank = BottomNavItem::make();
    $blank->applyAttributes(['id' => 'home', 'label' => 'Home', 'ref' => '']);

    expect($named->toArray(new CallbackRegistry)['props']['ref'])->toBe('tab-home')
        ->and($blank->toArray(new CallbackRegistry)['props'])->not->toHaveKey('ref');
});

it('publishes builder tab refs on the native root tabs, search tab included', function () {
    $items = tabItemsOf(Native::test(ChromeScreen::class, layout: RefTabsLayout::class)->tree());

    expect(array_map(fn (array $i) => $i['props']['ref'] ?? null, $items))
        ->toBe(['tab-home', null, 'tab-search']);
});

it('publishes an inline bottom-nav-item ref on the native root tabs', function () {
    $tree = Native::test(RefBottomNavScreen::class)->tree();
    $items = tabItemsOf($tree);

    expect($tree['type'])->toBe('native_root_tabs')
        ->and($items[0]['props']['ref'])->toBe('tab-home')
        ->and($items[1]['props'])->not->toHaveKey('ref');
});
