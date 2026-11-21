<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * Tabs for the ->children() tests: a Chats tab and a Settings tab. The
 * layout leaves the back button, the tab bar's visibility and the active
 * tab alone, so whatever the tests see there was decided by the bars.
 *
 * A test can make the layout set either bar on purpose through the data
 * it visits the screen with: `layoutBack` goes to NavBar::back() and
 * `layoutTabsHidden` to TabBar::hidden().
 */
class RouteTabsLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        $bar = NavBar::make()->title($screen->navTitle());

        if (($back = $screen->data('layoutBack')) !== null) {
            $bar->back($back);
        }

        return $bar;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Chats', '/chats'))
            ->add(Tab::link('Settings', '/settings'))
            ->hidden($screen->data('layoutTabsHidden'));
    }
}
