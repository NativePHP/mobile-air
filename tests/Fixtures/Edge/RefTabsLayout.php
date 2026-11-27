<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/** Builder tabs where some items carry a `ref` UI-test handle. */
class RefTabsLayout extends NativeLayout
{
    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/', icon: 'home')->ref('tab-home'))
            ->add(Tab::link('Profile', '/profile', icon: 'person'))
            ->add(Tab::search('Search', icon: 'search')->ref('tab-search'));
    }
}
