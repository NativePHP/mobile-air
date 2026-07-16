<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class BottomSheet extends Element
{
    protected string $type = 'bottom_sheet';

    protected array $props = [];

    protected ?string $dismissMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['visible'])) {
            $this->props['visible'] = (bool) $attrs['visible'];
        }
    }

    public function onDismiss(string $method): static
    {
        $this->dismissMethod = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if ($this->dismissMethod !== null) {
            $this->props['on_dismiss'] = $registry->register($this->dismissMethod);
        }

        return $this->props;
    }
}
