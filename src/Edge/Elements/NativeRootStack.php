<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Sentinel element emitted by `wrapWithChrome` when a layout opts into
 * native chrome via `NativeLayout::usesNativeChrome() = true` and only a
 * NavBar (no TabBar) is present.
 *
 * Carries the NavBar config as flat props (title, subtitle, back,
 * background_color, text_color, elevation, current_uri) plus per-screen
 * action items as `top_bar_action` children. The screen's rendered
 * content is appended as the final child.
 *
 * iOS / Android renderers detect this element type and route to native
 * `NavigationStack` / `NavHost` chrome instead of laying out chrome via
 * the custom `TopBar` HStack renderer.
 */
class NativeRootStack extends Element
{
    protected string $type = 'native_root_stack';

    protected array $props = [];

    /** Method name for the inline-search query callback; registered in resolveProps. */
    private ?string $searchOnQueryMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['title'])) {
            $this->props['title'] = $attrs['title'];
        }
        if (isset($attrs['subtitle'])) {
            $this->props['subtitle'] = $attrs['subtitle'];
        }
        if (isset($attrs['back'])) {
            $this->props['back'] = (bool) $attrs['back'];
        }
        if (isset($attrs['backgroundColor'])) {
            $this->props['background_color'] = $attrs['backgroundColor'];
        }
        if (isset($attrs['textColor'])) {
            $this->props['text_color'] = $attrs['textColor'];
        }
        if (isset($attrs['fontName'])) {
            $this->props['font_name'] = $attrs['fontName'];
        }
        if (isset($attrs['elevation'])) {
            $this->props['elevation'] = (int) $attrs['elevation'];
        }
        // Per-screen nav-bar opt-out — hides the toolbar for this
        // destination while the NavigationStack itself survives.
        if (isset($attrs['hideNavBar'])) {
            $this->props['hide_nav_bar'] = (bool) $attrs['hideNavBar'];
        }
        // Title display mode for the iOS NavigationStack toolbar — `large`,
        // `inline` (default), or `automatic`.
        if (isset($attrs['displayMode'])) {
            $this->props['display_mode'] = $attrs['displayMode'];
        }
        // Top-bar scroll behavior — `collapse` | `pinned` | `enterAlways`.
        // Android maps to a Material 3 TopAppBarScrollBehavior; iOS uses it
        // to pin or tuck the `.searchable` field.
        if (isset($attrs['scrollBehavior'])) {
            $this->props['scroll_behavior'] = $attrs['scrollBehavior'];
        }
        // The URI of the screen currently being published. The iOS
        // NavigationCoordinator keys per-URI tree caches off this so it
        // can render the correct content during NavigationStack push /
        // pop transitions.
        if (isset($attrs['currentUri'])) {
            $this->props['current_uri'] = $attrs['currentUri'];
        }

        // Inline NavBar search field — Apple HIG / Expo pattern.
        // iOS attaches `.searchable` to the destination view; Android
        // shows an M3 search field in the top app bar slot.
        if (isset($attrs['searchPlaceholder'])) {
            $this->props['search_placeholder'] = $attrs['searchPlaceholder'];
        }
        if (isset($attrs['searchOnQuery'])) {
            $this->searchOnQueryMethod = $attrs['searchOnQuery'];
        }
        if (isset($attrs['searchDebounceMs'])) {
            $this->props['search_debounce_ms'] = (int) $attrs['searchDebounceMs'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if ($this->searchOnQueryMethod !== null) {
            $this->props['search_on_query'] = $registry->register($this->searchOnQueryMethod);
        }

        return $this->props;
    }
}
