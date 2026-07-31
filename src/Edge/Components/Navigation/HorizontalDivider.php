<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class HorizontalDivider extends EdgeComponent
{
    protected string $type = 'horizontal_divider';

    protected bool $hasChildren = false;

    public function __construct()
    {
        // No parameters needed for a simple divider
    }

    protected function toNativeProps(): array
    {
        // No props needed - just renders a visual divider
        return [];
    }
}
