<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class SideNav extends NativeBladeComponent
{
    protected function elementType(): string
    {
        return 'side_nav';
    }
}
