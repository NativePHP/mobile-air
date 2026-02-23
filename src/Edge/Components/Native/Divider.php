<?php

namespace Native\Mobile\Edge\Components\Native;

class Divider extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'divider';
    }
}
