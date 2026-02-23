<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class ActivityIndicator extends Element
{
    protected string $type = 'activity_indicator';

    protected array $indicatorProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }
        if (isset($attrs['size'])) {
            $this->size($attrs['size']);
        }
    }

    public function color(string $color): static
    {
        $this->indicatorProps['color'] = $color;

        return $this;
    }

    public function size(string|int $size): static
    {
        // Convert string sizes to int for Kotlin renderer
        // 1 = large, 2 = small, 0 = medium (default)
        $this->indicatorProps['size'] = match ($size) {
            'large', 1 => 1,
            'small', 2 => 2,
            default => 0,
        };

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->indicatorProps;
    }
}
