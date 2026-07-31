<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\Element;

class Divider extends Element
{
    protected string $type = 'divider';

    public static function make(): static
    {
        return new static;
    }
}
