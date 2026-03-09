<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\Element;

class Pressable extends Element
{
    protected string $type = 'pressable';

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }
}
