<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Marker element wrapping a `NativeLayout::tabBarAccessory()` result so
 * the iOS / Android renderer can pick it out of `NativeRootTabs`'s
 * children alongside tabs (`bottom_nav_item`) and screen content.
 *
 * Renders into SwiftUI's `.tabViewBottomAccessory { … }` slot on
 * iOS 26+ — Apple's Music MiniPlayer pattern. Pre-iOS 26 it stacks
 * above the tab bar as a regular row.
 */
class TabAccessory extends Element
{
    protected string $type = 'tab_accessory';

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        // No own attributes — the wrapped child carries the actual content.
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
