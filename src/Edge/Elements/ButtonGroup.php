<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class ButtonGroup extends Element
{
    protected string $type = 'button_group';

    protected array $buttonGroupProps = [];

    protected ?string $changeCallback = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['options'])) {
            $this->options($attrs['options']);
        }
        if (isset($attrs['selectedIndex'])) {
            $this->selectedIndex((int) $attrs['selectedIndex']);
        }
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }
        if (! empty($attrs['disabled'])) {
            $this->disabled();
        }
    }

    public function options(array $options): static
    {
        $this->buttonGroupProps['options'] = $options;

        return $this;
    }

    public function selectedIndex(int $index): static
    {
        $this->buttonGroupProps['selected_index'] = $index;

        return $this;
    }

    public function color(string $color): static
    {
        $this->buttonGroupProps['color'] = $color;

        return $this;
    }

    public function disabled(bool $value = true): static
    {
        $this->buttonGroupProps['disabled'] = $value;

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->changeCallback = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->buttonGroupProps;

        if ($this->changeCallback !== null) {
            $props['on_change'] = $registry->register($this->changeCallback);
        }

        return $props;
    }
}
