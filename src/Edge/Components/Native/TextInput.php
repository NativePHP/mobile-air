<?php

namespace Native\Mobile\Edge\Components\Native;

class TextInput extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'text_input';
    }
}
