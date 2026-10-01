<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * Custom-Column chrome path (usesNativeChrome() = false) — the bars
 * render as `top_bar` / `bottom_nav` elements instead of a native
 * sentinel.
 */
class ChromeColumnLayout extends NativeLayout
{
    public function navBar(NativeComponent $screen): ?NavBar
    {
        return NavBar::make()->title($screen->navTitle());
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/'))
            ->add(Tab::link('Detail', '/detail/1'))
            ->highlight('/');
    }
}
