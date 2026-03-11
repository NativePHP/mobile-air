<?php

namespace NativePHP\ComposeUI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Slider extends Element
{
    protected string $type = 'slider';

    protected array $props = [];

    protected ?string $changeMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['value'])) {
            $this->props['value'] = (float) $attrs['value'];
        }
        if (isset($attrs['min'])) {
            $this->props['min'] = (float) $attrs['min'];
        }
        if (isset($attrs['max'])) {
            $this->props['max'] = (float) $attrs['max'];
        }
        if (isset($attrs['step'])) {
            $this->props['step'] = (float) $attrs['step'];
        }
        if (isset($attrs['color'])) {
            $this->props['color'] = $attrs['color'];
        }
        if (isset($attrs['trackColor'])) {
            $this->props['track_color'] = $attrs['trackColor'];
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

    protected function defaults(): array
    {
        return ['min' => 0, 'max' => 1, 'color' => '#007AFF'];
    }

    protected function layoutDefaults(): array
    {
        return ['min_height' => 32];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if ($this->changeMethod !== null) {
            $this->props['on_change'] = $registry->register($this->changeMethod);
        }

        return $this->props;
    }
}
