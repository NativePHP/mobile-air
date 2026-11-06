<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\NativeComponent;

class TabReselectLayout extends ChromeTabsLayout
{
    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/')->onReselect("backToTop('latest')"))
            ->add(Tab::link('Detail', '/detail/1'))
            ->highlight('/');
    }
}
