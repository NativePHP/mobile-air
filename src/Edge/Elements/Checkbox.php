<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Checkbox extends Element
{
    protected string $type = 'checkbox';

    protected array $props = [];

    protected ?string $changeMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['value'])) {
            $this->props['value'] = (bool) $attrs['value'];
        }
        if (isset($attrs['label'])) {
            $this->props['label'] = $attrs['label'];
        }
        if (isset($attrs['labelColor'])) {
            $this->props['label_color'] = $attrs['labelColor'];
        }
        if (isset($attrs['disabled'])) {
            $this->props['disabled'] = (bool) $attrs['disabled'];
        }
    }

    public function onChange(string $method): static
    {
        $this->changeMethod = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if ($this->changeMethod !== null) {
            $this->props['on_change'] = $registry->register($this->changeMethod);
        }

        return $this->props;
    }
}
