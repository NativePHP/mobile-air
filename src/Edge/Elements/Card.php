<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Card extends Element
{
    protected string $type = 'card';

    public static function make(): static
    {
        return new static;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
