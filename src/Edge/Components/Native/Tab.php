<?php

namespace Native\Mobile\Edge\Components\Native;

class Tab extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'tab';
    }
}
