<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Badge extends Element
{
    protected string $type = 'badge';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['text'])) {
            $this->props['text'] = $attrs['text'];
        }
        if (isset($attrs['count'])) {
            $this->props['count'] = (int) $attrs['count'];
        }
        if (isset($attrs['color'])) {
            $this->props['color'] = $attrs['color'];
        }
        if (isset($attrs['textColor'])) {
            $this->props['text_color'] = $attrs['textColor'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
