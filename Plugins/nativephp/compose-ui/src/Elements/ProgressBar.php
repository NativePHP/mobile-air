<?php

namespace NativePHP\ComposeUI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class ProgressBar extends Element
{
    protected string $type = 'progress_bar';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['value'])) {
            $this->props['value'] = (float) $attrs['value'];
        }
        if (isset($attrs['color'])) {
            $this->props['color'] = $attrs['color'];
        }
        if (isset($attrs['trackColor'])) {
            $this->props['track_color'] = $attrs['trackColor'];
        }
    }

    protected function defaults(): array
    {
        return ['color' => '#007AFF'];
    }

    protected function layoutDefaults(): array
    {
        return ['min_height' => 4];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
