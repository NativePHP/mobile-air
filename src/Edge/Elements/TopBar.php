<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class TopBar extends Element
{
    protected string $type = 'top_bar';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach (['title', 'subtitle', 'backgroundColor', 'textColor', 'fontName'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = $attrs[$key];
            }
        }

        if (isset($attrs['showNavigationIcon'])) {
            $this->props['show_navigation_icon'] = filter_var($attrs['showNavigationIcon'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['elevation'])) {
            $this->props['elevation'] = (int) $attrs['elevation'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
