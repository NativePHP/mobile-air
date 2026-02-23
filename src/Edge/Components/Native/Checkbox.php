<?php

namespace Native\Mobile\Edge\Components\Native;

class Checkbox extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'checkbox';
    }
}
