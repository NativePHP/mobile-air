<?php

namespace Native\Mobile\Edge\Components\Native;

class Button extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'button';
    }
}
