<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Select extends Element
{
    protected string $type = 'select';

    protected array $selectProps = [];

    protected ?string $changeCallback = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['value'])) {
            $this->value($attrs['value']);
        }
        if (isset($attrs['placeholder'])) {
            $this->placeholder($attrs['placeholder']);
        }
        if (isset($attrs['options'])) {
            $this->options($attrs['options']);
        }
        if (! empty($attrs['disabled'])) {
            $this->disabled();
        }
    }

    public function value(string $val): static
    {
        $this->selectProps['value'] = $val;

        return $this;
    }

    public function options(array $options): static
    {
        $this->selectProps['options'] = $options;

        return $this;
    }

    public function placeholder(string $text): static
    {
        $this->selectProps['placeholder'] = $text;

        return $this;
    }

    public function disabled(bool $value = true): static
    {
        $this->selectProps['disabled'] = $value;

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->changeCallback = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->selectProps;

        if ($this->changeCallback !== null) {
            $props['on_change'] = $registry->register($this->changeCallback);
        }

        return $props;
    }
}
