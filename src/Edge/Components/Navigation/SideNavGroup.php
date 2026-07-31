<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class SideNavGroup extends EdgeComponent
{
    protected string $type = 'side_nav_group';

    protected bool $hasChildren = true;

    public function __construct(
        public ?string $heading = null,
        public bool $expanded = false,
        public ?string $icon = null,
    ) {}

    protected function requiredProps(): array
    {
        return ['heading'];
    }

    protected function toNativeProps(): array
    {
        return array_filter([
            'heading' => $this->heading,
            'expanded' => $this->expanded,
            'icon' => $this->icon,
        ], fn ($value) => $value !== null);
    }
}
