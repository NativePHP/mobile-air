<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class ActivityIndicator extends Element
{
    protected string $type = 'activity_indicator';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['size'])) {
            $this->props['size'] = (float) $attrs['size'];
        }
        if (isset($attrs['color'])) {
            $this->props['color'] = $attrs['color'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
