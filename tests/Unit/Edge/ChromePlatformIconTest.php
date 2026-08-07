<?php

use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\PlatformIconChromeScreen;

/**
 * `:ios-icon` / `:android-icon` on inline chrome — enum cases (or raw
 * strings) resolved per platform through IconResolver, with the plain
 * `icon` attr as the cross-platform fallback. Same contract as the
 * `<icon>` element and the Tab/NavAction builders.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

function chromeIconNodes(TestableComponent $screen): array
{
    $found = [];
    $walk = function (array $node) use (&$walk, &$found) {
        $type = $node['type'] ?? '';
        if (in_array($type, ['top_bar_action', 'bottom_nav_item'], true)) {
            $found[$type] = $node['props'] ?? [];
        }
        // The fab is a pressable whose first child is the icon.
        if ($type === 'pressable') {
            foreach ($node['children'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'icon') {
                    $found['fab_icon'] = $c['props'] ?? [];
                }
            }
        }
        foreach ($node['children'] ?? [] as $c) {
            $walk($c);
        }
    };
    $walk($screen->tree());

    return $found;
}

it('resolves chrome icons to the iOS enum values on ios', function () {
    $nodes = chromeIconNodes(Native::test(PlatformIconChromeScreen::class, platform: 'ios'));

    expect($nodes['top_bar_action']['icon'])->toBe('star.fill');
    expect($nodes['bottom_nav_item']['icon'])->toBe('tray.full');
    expect($nodes['fab_icon']['name'])->toBe('plus');
    expect($nodes['top_bar_action'])->not->toHaveKey('material_variant');
});

it('resolves chrome icons to the Android enum values plus variant on android', function () {
    $nodes = chromeIconNodes(Native::test(PlatformIconChromeScreen::class, platform: 'android'));

    expect($nodes['top_bar_action']['icon'])->toBe('star_outline');
    expect($nodes['top_bar_action']['material_variant'])->toBe('outlined');
    expect($nodes['bottom_nav_item']['icon'])->toBe('inbox');
    expect($nodes['bottom_nav_item']['material_variant'])->toBe('outlined');
    expect($nodes['fab_icon']['name'])->toBe('add');
    expect($nodes['fab_icon']['material_variant'])->toBe('outlined');
});

it('falls back to the shared icon string when the platform is unknown', function () {
    $nodes = chromeIconNodes(Native::test(PlatformIconChromeScreen::class));

    expect($nodes['top_bar_action']['icon'])->toBe('star');
    expect($nodes['bottom_nav_item']['icon'])->toBe('inbox');
});
