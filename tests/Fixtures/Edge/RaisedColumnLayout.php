<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * A raised tab on the custom-Column chrome path (usesNativeChrome() =
 * false), where no native tab chrome exists to draw the disc.
 */
class RaisedColumnLayout extends NativeLayout
{
    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/'))
            ->add(Tab::link('Create', '/create')->raised());
    }
}
