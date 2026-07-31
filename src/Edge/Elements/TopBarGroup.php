<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class TopBarGroup extends Element
{
    protected string $type = 'top_bar_group';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach (['id', 'icon', 'label'] as $key) {
            if (isset($attrs[$key])) {
                $this->props[$key] = $attrs[$key];
            }
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}