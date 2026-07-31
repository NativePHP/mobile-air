<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\Element;

class Row extends Element
{
    protected string $type = 'row';

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }
}
