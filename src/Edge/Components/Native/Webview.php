<?php

namespace Native\Mobile\Edge\Components\Native;

class Webview extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'webview';
    }
}
