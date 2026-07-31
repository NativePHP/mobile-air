<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Marker element wrapping a `NavBar::titleView()` / `NavBar::logo()` result so
 * the iOS / Android native-chrome renderers can pick it out of the
 * `NativeRootStack` / `NativeRootTabs` children (alongside `top_bar_action`s and
 * screen content) and render it in the bar's centered principal slot instead of
 * the string title.
 *
 * On iOS it becomes a `ToolbarItem(placement: .principal)`; on Android it fills
 * the `TopAppBar`'s `title = { … }` slot. When absent, the renderers fall back
 * to the plain `title` string prop, so this is purely additive.
 */
class TopBarTitle extends Element
{
    protected string $type = 'top_bar_title';

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
