<?php

namespace Tests\Fixtures\Edge;

class HiddenTabScreen extends ChromeScreen
{
    protected bool $hidesTabBar = true;

    public function navTitle(): string
    {
        return 'Pushed Detail';
    }
}
