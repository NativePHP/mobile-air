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
}
