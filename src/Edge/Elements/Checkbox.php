<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Checkbox extends Element
{
    protected string $type = 'checkbox';

    protected array $checkboxProps = [];

    protected ?string $changeCallback = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['value'])) {
            $this->value((bool) $attrs['value']);
        }
        if (isset($attrs['label'])) {
            $this->label($attrs['label']);
        }
        if (isset($attrs['labelColor'])) {
            $this->labelColor($attrs['labelColor']);
        }
        if (! empty($attrs['disabled'])) {
            $this->disabled();
        }
    }

    public function value(bool $checked): static
    {
        $this->checkboxProps['value'] = $checked;

        return $this;
    }

    public function label(string $label): static
    {
        $this->checkboxProps['label'] = $label;

        return $this;
    }

    public function labelColor(string $color): static
    {
        $this->checkboxProps['label_color'] = $color;

        return $this;
    }

    public function disabled(bool $value = true): static
    {
        $this->checkboxProps['disabled'] = $value;

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->changeCallback = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->checkboxProps;

        if ($this->changeCallback !== null) {
            $props['on_change'] = $registry->register($this->changeCallback);
        }

        return $props;
    }
}
