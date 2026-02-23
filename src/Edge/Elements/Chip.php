<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Chip extends Element
{
    protected string $type = 'chip';

    protected array $chipProps = [];

    public static function make(string $label = ''): static
    {
        $el = new static;
        if ($label !== '') {
            $el->chipProps['label'] = $label;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['label'])) {
            $this->label($attrs['label']);
        }
        if (isset($attrs['selected'])) {
            $this->selected((bool) $attrs['selected']);
        }
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }
        if (isset($attrs['icon'])) {
            $this->icon($attrs['icon']);
        }
    }

    public function label(string $label): static
    {
        $this->chipProps['label'] = $label;

        return $this;
    }

    public function selected(bool $selected = true): static
    {
        $this->chipProps['selected'] = $selected;

        return $this;
    }

    public function color(string $color): static
    {
        $this->chipProps['color'] = $color;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->chipProps['icon'] = $icon;

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->chipProps['on_change'] = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->chipProps;

        if (isset($props['on_change'])) {
            $props['on_change'] = $registry->register($props['on_change']);
        }

        return $props;
    }
}
