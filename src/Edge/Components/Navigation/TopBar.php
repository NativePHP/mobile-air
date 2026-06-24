<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class TopBar extends EdgeComponent
{
    protected string $type = 'top_bar';

    protected bool $hasChildren = true;

    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public bool $showNavigationIcon = true,
        public ?string $backgroundColor = null,
        public ?string $textColor = null,
        public ?int $elevation = null
    ) {}

    protected function toNativeProps(): array
    {
        return array_filter([
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'show_navigation_icon' => $this->showNavigationIcon,
            'background_color' => $this->backgroundColor,
            'text_color' => $this->textColor,
            'elevation' => $this->elevation,
        ], fn ($value) => $value !== null && $value !== false);
    }
}
