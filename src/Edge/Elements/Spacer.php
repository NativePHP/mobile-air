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

    /**
     * Spacer's whole purpose is to claim remaining space inside a flex
     * container. Default flex_grow=1 so authors can drop <native:spacer />
     * in and it works without remembering to add a class.
     */
    protected function layoutDefaults(): array
    {
        return ['flex_grow' => 1];
    }
}
