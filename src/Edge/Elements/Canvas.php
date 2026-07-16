<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\Element;

class Canvas extends Element
{
    protected string $type = 'canvas';

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }
}
