<?php

namespace NativePHP\ComposeUI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Fab extends Element
{
    protected string $type = 'fab';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach (['icon', 'label', 'url', 'event', 'size', 'position', 'containerColor', 'contentColor'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = $attrs[$key];
            }
        }

        foreach (['bottomOffset', 'elevation', 'cornerRadius'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = (int) $attrs[$key];
            }
        }
    }

    protected function defaults(): array
    {
        return ['color' => '#007AFF', 'icon_color' => '#FFFFFF'];
    }

    protected function styleDefaults(): array
    {
        return ['border_radius' => 28, 'elevation' => 6];
    }

    protected function layoutDefaults(): array
    {
        return ['min_width' => 56, 'min_height' => 56];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
