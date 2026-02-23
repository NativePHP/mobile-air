<?php

namespace Native\Mobile\Edge\Components\Native;

class Icon extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'icon';
    }
}
