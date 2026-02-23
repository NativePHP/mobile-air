<?php

namespace Native\Mobile\Edge\Components\Native;

class Spacer extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'spacer';
    }
}
