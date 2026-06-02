<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class TopBarSection extends EdgeComponent
{
    protected string $type = 'top_bar_section';

    protected bool $hasChildren = true;

    public function __construct(
        public ?string $title = null,
    ) {}

    protected function requiredProps(): array
    {
        return [];
    }

    protected function toNativeProps(): array
    {
        return [
            'title' => $this->title,
        ];
    }
}
