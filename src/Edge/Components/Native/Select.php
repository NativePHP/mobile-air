<?php

namespace Native\Mobile\Edge\Components\Native;

class Select extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'select';
    }
}
