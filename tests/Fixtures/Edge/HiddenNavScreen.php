<?php

namespace Tests\Fixtures\Edge;

class HiddenNavScreen extends ChromeScreen
{
    protected bool $hidesNavBar = true;

    public function navTitle(): string
    {
        return 'Immersive';
    }
}
