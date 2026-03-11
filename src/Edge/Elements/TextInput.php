<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class TextInput extends Element
{
    protected string $type = 'text_input';

    protected array $props = [];

    protected ?string $changeMethod = null;

    protected ?string $submitMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['placeholder'])) {
            $this->props['placeholder'] = $attrs['placeholder'];
        }
        if (isset($attrs['value'])) {
            $this->props['value'] = $attrs['value'];
        }
        if (isset($attrs['disabled'])) {
            $this->props['disabled'] = (bool) $attrs['disabled'];
        }
        if (isset($attrs['secure'])) {
            $this->props['secure'] = (bool) $attrs['secure'];
        }
        if (isset($attrs['keyboardType'])) {
            $this->props['keyboard_type'] = $attrs['keyboardType'];
        }
    }

    public function onChange(string $method): static
    {
        $this->changeMethod = $method;

        return $this;
    }

    public function onSubmit(string $method): static
    {
        $this->submitMethod = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if ($this->changeMethod !== null) {
            $this->props['on_change'] = $registry->register($this->changeMethod);
        }
        if ($this->submitMethod !== null) {
            $this->props['on_submit'] = $registry->register($this->submitMethod);
        }

        return $this->props;
    }
}
