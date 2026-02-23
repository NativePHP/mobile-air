<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class TextInput extends Element
{
    protected string $type = 'text_input';

    protected array $inputProps = [];

    protected ?string $changeCallback = null;

    protected ?string $submitCallback = null;

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
        if (isset($attrs['keyboard'])) {
            $this->keyboard((int) $attrs['keyboard']);
        }
        if (! empty($attrs['secure'])) {
            $this->secure();
        }
        if (isset($attrs['maxLength'])) {
            $this->maxLength((int) $attrs['maxLength']);
        }
        if (! empty($attrs['multiline'])) {
            $this->multiline();
        }
    }

    public function value(string $text): static
    {
        $this->inputProps['value'] = $text;

        return $this;
    }

    public function placeholder(string $text): static
    {
        $this->inputProps['placeholder'] = $text;

        return $this;
    }

    public function keyboard(int $type): static
    {
        $this->inputProps['keyboard'] = $type;

        return $this;
    }

    public function secure(bool $value = true): static
    {
        $this->inputProps['secure'] = $value;

        return $this;
    }

    public function maxLength(int $length): static
    {
        $this->inputProps['max_length'] = $length;

        return $this;
    }

    public function multiline(bool $value = true): static
    {
        $this->inputProps['multiline'] = $value;

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->changeCallback = $method;

        return $this;
    }

    public function onSubmit(string $method): static
    {
        $this->submitCallback = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->inputProps;

        if ($this->changeCallback !== null) {
            $props['on_change'] = $registry->register($this->changeCallback);
        }

        if ($this->submitCallback !== null) {
            $props['on_submit'] = $registry->register($this->submitCallback);
        }

        return $props;
    }
}
