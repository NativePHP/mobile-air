<?php

namespace Native\Mobile\Edge\Components\Native;

class Radio extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'radio';
    }
}
