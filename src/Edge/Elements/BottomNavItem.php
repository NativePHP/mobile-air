<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class BottomNavItem extends Element
{
    protected string $type = 'bottom_nav_item';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach (['id', 'icon', 'url', 'label', 'badge', 'badgeColor'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = $attrs[$key];
            }
        }

        if (isset($attrs['active'])) {
            $this->props['active'] = filter_var($attrs['active'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['news'])) {
            $this->props['news'] = filter_var($attrs['news'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['search'])) {
            $this->props['search'] = filter_var($attrs['search'], FILTER_VALIDATE_BOOLEAN);
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if (!empty($this->props['url']) && $this->pressMethod === null) {
            // Tab taps should `replace` the current screen, not push onto
            // the stack. Otherwise tapping Chats → Friends → Profile builds
            // up a 4-deep stack, and the framework back chevron pops one
            // tab at a time instead of returning to where the user came
            // from before entering the tabs section.
            $this->setNavigateConfig([
                'type' => 'replace',
                'uri' => $this->props['url'],
                'data' => [],
                'transition' => 'none',
            ]);
        }

        return $this->props;
    }
}
