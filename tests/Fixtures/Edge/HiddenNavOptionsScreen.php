<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;

class HiddenNavOptionsScreen extends ChromeScreen
{
    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->hidden();
    }
}
