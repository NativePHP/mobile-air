<?php

namespace Native\Mobile\Edge\Layouts;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\NativeComponent;

/**
 * Base class for navigation layouts. Subclasses declare what chrome
 * (top nav bar / bottom tab bar) wraps the screens routed under them.
 *
 * Returning null from a method means "don't render that chrome." Layouts
 * compose by attaching different layout classes to different routes —
 * a tab home screen uses a TabsLayout, a pushed detail screen uses a
 * StackLayout. The framework swaps chrome automatically as the user
 * navigates.
 */
abstract class NativeLayout
{
    /**
     * Chrome font for this layout — a resources/fonts/ file token (e.g.
     * `Lobster-Regular`). Applies to the layout's nav bar and tab bar unless
     * a bar sets its own ->font(). Null defers to the theme's app-wide
     * `font-family` default. iOS note: system-drawn LARGE titles keep the
     * app-default font (UIKit limitation); inline titles, subtitles, tab
     * labels, and Android honor this fully.
     */
    protected ?string $font = null;

    /** The layout-wide chrome font token, or null. */
    public function chromeFont(): ?string
    {
        return $this->font;
    }

    /**
     * Return the top navigation bar for this screen, or null for none.
     * The screen is passed in so the layout can read $screen->navTitle()
     * or other declared properties.
     */
    public function navBar(NativeComponent $screen): ?NavBar
    {
        return null;
    }

    /**
     * Return the bottom tab bar for this screen, or null for none.
     */
    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return null;
    }

    /**
     * Optional persistent component pinned above the tab bar — Apple's
     * Music MiniPlayer pattern. Renders inside SwiftUI's
     * `.tabViewBottomAccessory { … }` slot on iOS 26+, falls back to a
     * regular row above the bar on older iOS. Combine with
     * `TabBar::minimizeOnScroll()` to get the auto-minimize behavior
     * where the accessory tucks inline with the active tab on scroll.
     *
     * Only consulted on layouts where `usesNativeChrome() = true`.
     */
    public function tabBarAccessory(NativeComponent $screen): ?Element
    {
        return null;
    }

    /**
     * Optional bottom-pinned content — chat input, search bar,
     * contextual menu. Renders via `.safeAreaInset(edge: .bottom)` on
     * iOS (keyboard avoidance is automatic) and `Scaffold(bottomBar =
     * …)` + `imePadding()` on Compose.
     *
     * Returns any composable Element tree. Style with the `glass` /
     * `glass-thick` Tailwind classes for Liquid Glass capsules. Survives
     * pushes inside a `NavigationStack` (iOS) — i.e. a chat-detail-only
     * input bar can be returned conditionally based on `$screen` type.
     *
     * Only consulted on layouts where `usesNativeChrome() = true`.
     */
    public function bottomBar(NativeComponent $screen): ?Element
    {
        return null;
    }

    /**
     * Opt this layout into native chrome rendering — `NavigationStack` /
     * `TabView` on iOS, `NavHost` / `Scaffold` on Android. When `true`,
     * the framework emits a `NativeRootStack` / `NativeRootTabs` element
     * carrying the bar config as serialized props instead of the current
     * Column-of-[navBar, content, tabBar] tree. The native renderer takes
     * over from there: edge-swipe-back, predictive-back, large titles,
     * and (on iOS 26+) Liquid Glass — all for free.
     *
     * Default `false` so existing layouts keep their custom-drawn chrome
     * behavior. Layouts opt in one at a time as they're ready.
     *
     * Three-tier appearance contract:
     *  - `TabBar` / `NavBar` with no `backgroundColor()` set → system
     *    Liquid Glass / Material You.
     *  - `backgroundColor()` set → opaque native bar with custom solid
     *    colors (the X / Instagram path).
     *  - Inline `<native:top-bar>` / `<native:bottom-nav>` in the screen's
     *    blade → wins over this layout's bar for that slot. The inline bar
     *    is hoisted and drives the same native chrome sentinel the builders
     *    feed (the other slot still comes from the layout); adding the
     *    boolean `custom` attribute instead keeps the bar in the content
     *    tree as a custom-drawn element while still suppressing the
     *    layout's bar for that slot.
     */
    public function usesNativeChrome(): bool
    {
        return false;
    }
}
