<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class SideNavHeader extends EdgeComponent
{
    protected string $type = 'side_nav_header';

    protected bool $hasChildren = false;

    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $icon = null,
        public ?string $backgroundColor = null,
        public ?string $imageUrl = null,
        public ?string $event = null,
        public bool $showCloseButton = true,
        public bool $pinned = false
    ) {}

    protected function toNativeProps(): array
    {
        return array_filter([
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'background_color' => $this->backgroundColor,
            'image_url' => $this->imageUrl,
            'event' => $this->event,
            'show_close_button' => $this->showCloseButton,
            'pinned' => $this->pinned,
        ], fn ($value) => $value !== null);
    }
}
