<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class TopBarSection extends Element
{
    protected string $type = 'top_bar_section';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['title'])) {
            $this->props['title'] = $attrs['title'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}