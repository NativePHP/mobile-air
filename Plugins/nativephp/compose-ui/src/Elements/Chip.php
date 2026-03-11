<?php

namespace NativePHP\ComposeUI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Chip extends Element
{
    protected string $type = 'chip';

    protected array $props = [];

    protected ?string $changeMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['label'])) {
            $this->props['label'] = $attrs['label'];
        }
        if (isset($attrs['icon'])) {
            $this->props['icon'] = $attrs['icon'];
        }
        if (isset($attrs['selected'])) {
            $this->props['selected'] = (bool) $attrs['selected'];
        }
        if (isset($attrs['disabled'])) {
            $this->props['disabled'] = (bool) $attrs['disabled'];
        }
        if (isset($attrs['color'])) {
            $this->props['color'] = $attrs['color'];
        }
        if (isset($attrs['labelColor'])) {
            $this->props['label_color'] = $attrs['labelColor'];
        }
    }

    public function onChange(string $method): static
    {
        $this->changeMethod = $method;

        return $this;
    }

    protected function defaults(): array
    {
        return ['color' => '#E0E0E0', 'text_color' => '#333333'];
    }

    protected function styleDefaults(): array
    {
        return ['border_radius' => 9999];
    }

    protected function layoutDefaults(): array
    {
        return ['padding' => [6, 12, 6, 12]];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        if ($this->changeMethod !== null) {
            $this->props['on_change'] = $registry->register($this->changeMethod);
        }

        return $this->props;
    }
}
