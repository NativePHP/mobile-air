<?php

namespace Native\Mobile\Edge\Components\Native;

class ActivityIndicator extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'activity_indicator';
    }
}
