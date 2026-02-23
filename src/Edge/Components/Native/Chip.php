<?php

namespace Native\Mobile\Edge\Components\Native;

class Chip extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'chip';
    }
}
