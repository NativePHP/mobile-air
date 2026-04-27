<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class TopBar extends NativeBladeComponent
{
    protected function elementType(): string
    {
        return 'top_bar';
    }
}
