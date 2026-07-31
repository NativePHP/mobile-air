<?php

namespace Native\Mobile\Edge\Components\Navigation;

use Native\Mobile\Edge\Components\EdgeComponent;

class SideNav extends EdgeComponent
{
    protected string $type = 'side_nav';

    protected bool $hasChildren = true;

    public function __construct(
        public ?bool $dark = null,
        public string $labelVisibility = 'labeled',
        public bool $gesturesEnabled = false
    ) {}

    protected function toNativeProps(): array
    {
        return [
            'dark' => $this->dark,
            'label_visibility' => $this->labelVisibility,
            'gestures_enabled' => $this->gesturesEnabled,
        ];
    }
}
