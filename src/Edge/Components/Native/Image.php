<?php

namespace Native\Mobile\Edge\Components\Native;

class Image extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'image';
    }
}
