<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\Element;

class Column extends Element
{
    protected string $type = 'column';

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }
}
