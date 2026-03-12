<?php

namespace NativePHP\ComposeUI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class ListItem extends Element
{
    protected string $type = 'list_item';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['headline'])) {
            $this->props['headline'] = $attrs['headline'];
        }
        if (isset($attrs['supporting'])) {
            $this->props['supporting'] = $attrs['supporting'];
        }
        if (isset($attrs['overline'])) {
            $this->props['overline'] = $attrs['overline'];
        }
        if (isset($attrs['leadingIcon'])) {
            $this->props['leading_icon'] = $attrs['leadingIcon'];
        }
        if (isset($attrs['trailingIcon'])) {
            $this->props['trailing_icon'] = $attrs['trailingIcon'];
        }
        if (isset($attrs['headlineColor'])) {
            $this->props['headline_color'] = $attrs['headlineColor'];
        }
        if (isset($attrs['supportingColor'])) {
            $this->props['supporting_color'] = $attrs['supportingColor'];
        }
    }

    protected function layoutDefaults(): array
    {
        return ['min_height' => 48, 'padding' => [8, 16, 8, 16]];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
