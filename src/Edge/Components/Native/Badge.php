<?php

namespace Native\Mobile\Edge\Components\Native;

class Badge extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'badge';
    }
}
