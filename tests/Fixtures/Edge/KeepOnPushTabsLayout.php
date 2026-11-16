<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/** Tabs that stay visible on pushed screens (TabBar::hideOnPush(false)). */
class KeepOnPushTabsLayout extends NativeLayout
{
    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/'))
            ->add(Tab::link('Shop', '/shop'))
            ->hideOnPush(false);
    }
}
