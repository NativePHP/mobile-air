<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\Element;

class Spacer extends Element
{
    protected string $type = 'spacer';

    public static function make(): static
    {
        return new static;
    }
}
