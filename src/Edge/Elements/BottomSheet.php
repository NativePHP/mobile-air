<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class BottomSheet extends Element
{
    protected string $type = 'bottom_sheet';

    protected array $sheetProps = [];

    protected ?string $dismissCallback = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['visible'])) {
            $this->visible((bool) $attrs['visible']);
        }
    }

    public function visible(bool $value = true): static
    {
        $this->sheetProps['visible'] = $value;

        return $this;
    }

    public function onDismiss(string $method): static
    {
        $this->dismissCallback = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->sheetProps;

        if ($this->dismissCallback !== null) {
            $props['on_dismiss'] = $registry->register($this->dismissCallback);
        }

        return $props;
    }
}
