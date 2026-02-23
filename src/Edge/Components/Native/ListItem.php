<?php

namespace Native\Mobile\Edge\Components\Native;

class ListItem extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'list_item';
    }
}
