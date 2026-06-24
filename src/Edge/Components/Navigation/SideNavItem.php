<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class SideNavItem extends EdgeComponent
{
    protected string $type = 'side_nav_item';

    protected bool $hasChildren = false;

    public function __construct(
        public ?string $id = null,
        public ?string $label = null,
        public ?string $url = null,
        public ?string $icon = null,
        public bool $active = false,
        public ?string $badge = null,
        public ?string $badgeColor = null,
        public bool $openInBrowser = false
    ) {}

    protected function requiredProps(): array
    {
        return ['id', 'label', 'url', 'icon'];
    }

    protected function toNativeProps(): array
    {
        return array_filter([
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'active' => $this->active,
            'badge' => $this->badge,
            'badge_color' => $this->badgeColor,
            'open_in_browser' => $this->openInBrowser,
        ], fn ($value) => $value !== null && $value !== false);
    }
}
