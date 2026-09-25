<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\RaisedTab;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/** Native tab chrome whose middle tab is raised into a disc. */
class RaisedTabsLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/', icon: 'home'))
            ->add(Tab::link('Create', '/create', icon: 'add')->raised(
                RaisedTab::make()
                    ->gradient('#3FBFA0', '#17977F', 150)
                    ->ring('white', 4)
                    ->icon(ios: FixtureIosIcon::Plus, android: FixtureAndroidIcon::Add)
            ))
            ->add(Tab::link('Inbox', '/inbox', icon: 'inbox'));
    }
}
