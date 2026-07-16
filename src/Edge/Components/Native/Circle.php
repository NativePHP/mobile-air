<?php

namespace Native\Mobile\Edge\Components\Native;

class Circle extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'circle';
    }
}
