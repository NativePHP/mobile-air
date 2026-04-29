<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class BottomNav extends Element
{
    protected string $type = 'bottom_nav';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['dark'])) {
            $this->props['dark'] = filter_var($attrs['dark'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['labelVisibility'])) {
            $this->props['label_visibility'] = $attrs['labelVisibility'];
        }

        if (isset($attrs['activeColor'])) {
            $this->props['active_color'] = $attrs['activeColor'];
        }

        if (isset($attrs['backgroundColor'])) {
            $this->props['background_color'] = $attrs['backgroundColor'];
        }

        if (isset($attrs['textColor'])) {
            $this->props['text_color'] = $attrs['textColor'];
        }

        $this->props['id'] = 'bottom_nav';
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
