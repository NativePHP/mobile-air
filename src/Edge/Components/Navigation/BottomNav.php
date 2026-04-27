<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class BottomNav extends NativeBladeComponent
{
    protected function elementType(): string
    {
        return 'bottom_nav';
    }
}
