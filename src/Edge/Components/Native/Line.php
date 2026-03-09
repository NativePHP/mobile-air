<?php

namespace Native\Mobile\Edge\Components\Native;

class Line extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'line';
    }
}
