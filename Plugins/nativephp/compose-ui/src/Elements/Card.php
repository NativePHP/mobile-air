<?php

namespace NativePHP\ComposeUI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Card extends Element
{
    protected string $type = 'card';

    public static function make(): static
    {
        return new static;
    }

    protected function styleDefaults(): array
    {
        return ['border_radius' => 12, 'elevation' => 2, 'bg_color' => '#FFFFFF'];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
