<?php

namespace Native\Mobile\Edge\Components\Native;

class Toggle extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'toggle';
    }
}
