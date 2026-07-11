<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class SideNavItem extends Element
{
    protected string $type = 'side_nav_item';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['badge-color']) && ! isset($attrs['badgeColor'])) {
            $attrs['badgeColor'] = $attrs['badge-color'];
        }

        foreach (['id', 'label', 'url', 'icon', 'badge', 'badgeColor'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = $attrs[$key];
            }
        }

        if (isset($attrs['active'])) {
            $this->props['active'] = filter_var($attrs['active'], FILTER_VALIDATE_BOOLEAN);
        }

        $openInBrowser = $attrs['open-in-browser'] ?? $attrs['openInBrowser'] ?? null;
        if ($openInBrowser !== null) {
            $this->props['open_in_browser'] = filter_var($openInBrowser, FILTER_VALIDATE_BOOLEAN);
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if (! empty($this->props['url']) && $this->pressMethod === null) {
            $this->setNavigateConfig([
                'type' => 'navigate',
                'uri' => $this->props['url'],
                'data' => [],
                'transition' => 'none',
            ]);
        }

        return $this->props;
    }
}
