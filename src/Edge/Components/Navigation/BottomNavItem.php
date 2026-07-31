<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class BottomNavItem extends EdgeComponent
{
    protected string $type = 'bottom_nav_item';

    public function __construct(
        public ?string $id = null,
        public ?string $icon = null,
        public ?string $url = null,
        public ?string $label = null,
        public bool $active = false,
        public ?string $badge = null,
        public ?string $badgeColor = null,
        public bool $news = false,
    ) {}

    protected function requiredProps(): array
    {
        return ['id', 'icon', 'url', 'label'];
    }

    protected function toNativeProps(): array
    {
        return [
            'id' => $this->id,
            'icon' => $this->icon,
            'url' => $this->url,
            'label' => $this->label,
            'active' => $this->active,
            'badge' => $this->badge,
            'badge_color' => $this->badgeColor,
            'news' => $this->news,
        ];
    }
}
