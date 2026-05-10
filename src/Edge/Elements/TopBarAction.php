<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class TopBarAction extends Element
{
    protected string $type = 'top_bar_action';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach (['id', 'icon', 'material_variant', 'label', 'url', 'event'] as $key) {
            if (isset($attrs[$key])) {
                $this->props[$key] = $attrs[$key];
            }
        }
        if (isset($attrs['destructive'])) {
            $this->props['destructive'] = (bool) $attrs['destructive'];
        }
        if (isset($attrs['divider'])) {
            $this->props['divider'] = (bool) $attrs['divider'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if (!empty($this->props['url']) && $this->pressMethod === null) {
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
