<?php

namespace Native\Mobile\Edge\Components\Native;

class Slider extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'slider';
    }
}
