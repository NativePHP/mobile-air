<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class SideNavGroup extends Element
{
    protected string $type = 'side_nav_group';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['heading'])) {
            $this->props['heading'] = $attrs['heading'];
        }

        if (isset($attrs['expanded'])) {
            $this->props['expanded'] = filter_var($attrs['expanded'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['icon'])) {
            $this->props['icon'] = $attrs['icon'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
