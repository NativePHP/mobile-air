<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class TopBarGroup extends EdgeComponent
{
    protected string $type = 'top_bar_action';

    protected bool $hasChildren = true;

    public function __construct(
        public ?string $id = null,
        public ?string $icon = null,
        public ?string $label = null,
    ) {}

    protected function requiredProps(): array
    {
        return ['id', 'icon', 'label'];
    }

    protected function toNativeProps(): array
    {
        return array_filter([
            'id' => $this->id,
            'icon' => $this->icon,
            'label' => $this->label,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
