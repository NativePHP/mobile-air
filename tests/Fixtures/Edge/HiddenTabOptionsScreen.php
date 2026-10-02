<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;

/**
 * Hides the tab bar via the `tabBarOptions()` builder rather than the
 * `$hidesTabBar` shortcut — the form reported in #250.
 */
class HiddenTabOptionsScreen extends ChromeScreen
{
    public function navTitle(): string
    {
        return 'Pushed Detail';
    }

    public function tabBarOptions(): ?TabBarOptions
    {
        return TabBarOptions::make()->hidden();
    }
}
