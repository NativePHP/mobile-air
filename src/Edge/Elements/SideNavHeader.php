<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class SideNavHeader extends Element
{
    protected string $type = 'side_nav_header';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach (['background-color' => 'backgroundColor', 'image-url' => 'imageUrl'] as $kebab => $camel) {
            if (isset($attrs[$kebab]) && ! isset($attrs[$camel])) {
                $attrs[$camel] = $attrs[$kebab];
            }
        }

        foreach (['title', 'subtitle', 'icon', 'backgroundColor', 'imageUrl', 'event'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = $attrs[$key];
            }
        }

        $showCloseButton = $attrs['show-close-button'] ?? $attrs['showCloseButton'] ?? null;
        if ($showCloseButton !== null) {
            $this->props['show_close_button'] = filter_var($showCloseButton, FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['pinned'])) {
            $this->props['pinned'] = filter_var($attrs['pinned'], FILTER_VALIDATE_BOOLEAN);
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
