<?php

namespace Native\Mobile\Edge\Components\Native;

class Rect extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'rect';
    }
}
