<?php

namespace Native\Mobile\Edge\Components\Native;

class ProgressBar extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'progress_bar';
    }
}
