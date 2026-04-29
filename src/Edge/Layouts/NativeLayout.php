<?php

namespace Native\Mobile\Edge\Layouts;

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
     * Three-tier appearance contract once opted in:
     *  - `TabBar` / `NavBar` with no `backgroundColor()` set → system
     *    Liquid Glass / Material You.
     *  - `backgroundColor()` set → opaque native bar with custom solid
     *    colors (the X / Instagram path).
     *  - Inline `<native:top-bar>` / `<native:bottom-nav>` in the screen's
     *    blade → bypasses native chrome entirely (use this layout method
     *    just for stacking semantics).
     */
    public function usesNativeChrome(): bool
    {
        return false;
    }
}
