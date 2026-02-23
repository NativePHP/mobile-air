<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Toggle extends Element
{
    protected string $type = 'toggle';

    protected array $toggleProps = [];

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
        if (! empty($attrs['disabled'])) {
            $this->disabled();
        }
    }

    public function value(bool $checked): static
    {
        $this->toggleProps['value'] = $checked;

        return $this;
    }

    public function disabled(bool $value = true): static
    {
        $this->toggleProps['disabled'] = $value;

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->changeCallback = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->toggleProps;

        if ($this->changeCallback !== null) {
            $props['on_change'] = $registry->register($this->changeCallback);
        }

        return $props;
    }
}
