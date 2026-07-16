<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class SideNav extends Element
{
    protected string $type = 'side_nav';

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

        $labelVisibility = $attrs['label-visibility'] ?? $attrs['labelVisibility'] ?? null;
        if ($labelVisibility !== null) {
            $this->props['label_visibility'] = $labelVisibility;
        }

        $gesturesEnabled = $attrs['gestures-enabled'] ?? $attrs['gesturesEnabled'] ?? null;
        if ($gesturesEnabled !== null) {
            $this->props['gestures_enabled'] = filter_var($gesturesEnabled, FILTER_VALIDATE_BOOLEAN);
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
