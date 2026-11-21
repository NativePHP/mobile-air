<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
use Native\Mobile\Edge\NativeComponent;

/**
 * A screen for the bar precedence tests. Everything it says about its own
 * bars comes from the data a test visits it with, and anything left out
 * says nothing, so the bars decide:
 *
 *  - `view`: the Blade view to render (default `chrome-screen`, no bars)
 *  - `tabBarHidden`: tabBarOptions()->hidden(...)
 *  - `hidesTabBar`: the $hidesTabBar shortcut
 *  - `navBack`: navigationOptions()->back(...)
 *  - `navState`: handed to setNavBar()
 */
class BarChoicesScreen extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Bar Choices';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        $back = $this->data('navBack');

        return $back === null ? null : NavBarOptions::make()->back($back);
    }

    public function tabBarOptions(): ?TabBarOptions
    {
        $hidden = $this->data('tabBarHidden');

        return $hidden === null ? null : TabBarOptions::make()->hidden($hidden);
    }

    public function render(): View
    {
        $this->hidesTabBar = (bool) $this->data('hidesTabBar', false);

        if (($state = $this->data('navState')) !== null) {
            $this->setNavBar($state);
        }

        return view($this->data('view', 'chrome-screen'));
    }
}
