<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class TopBarAction extends EdgeComponent
{
    protected string $type = 'top_bar_action';

    public function __construct(
        public ?string $id = null,
        public ?string $icon = null,
        public ?string $label = null,
        public ?string $url = null,
        public ?string $event = null,
    ) {}

    protected function requiredProps(): array
    {
        return ['id', 'icon', 'label', 'url'];
    }

    protected function toNativeProps(): array
    {
        return [
            'id' => $this->id,
            'icon' => $this->icon,
            'label' => $this->label,
            'url' => $this->url,
            'event' => $this->event,
        ];
    }
}
