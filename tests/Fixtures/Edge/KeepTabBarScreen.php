<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;

/** A screen that keeps the tab bar even when pushed: hidden(false). */
class KeepTabBarScreen extends ChromeScreen
{
    public function tabBarOptions(): ?TabBarOptions
    {
        return TabBarOptions::make()->hidden(false);
    }
}
