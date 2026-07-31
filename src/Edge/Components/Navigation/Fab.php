<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class Fab extends EdgeComponent
{
    protected string $type = 'fab';

    public function __construct(
        public ?string $icon = null,
        public ?string $label = null,
        public ?string $url = null,
        public ?string $event = null,
        public string $size = 'regular',
        public string $position = 'end',
        public ?int $bottomOffset = null,
        public ?int $elevation = null,
        public ?int $cornerRadius = null,
        public ?string $containerColor = null,
        public ?string $contentColor = null,
    ) {}

    protected function requiredProps(): array
    {
        return ['icon'];
    }

    protected function toNativeProps(): array
    {
        return array_filter([
            'icon' => $this->icon,
            'label' => $this->label,
            'url' => $this->url,
            'event' => $this->event,
            'size' => $this->size,
            'position' => $this->position,
            'bottom_offset' => $this->bottomOffset,
            'elevation' => $this->elevation,
            'corner_radius' => $this->cornerRadius,
            'container_color' => $this->containerColor,
            'content_color' => $this->contentColor,
        ], fn ($value) => $value !== null);
    }
}
