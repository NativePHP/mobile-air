<?php

namespace NativePHP\ComposeUI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class HorizontalDivider extends Element
{
    protected string $type = 'horizontal_divider';

    public static function make(): static
    {
        return new static;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
