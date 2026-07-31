<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\Element;

class Stack extends Element
{
    protected string $type = 'stack';

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }
}
