<?php

namespace NativePHP\ComposeUI\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Icon extends Element
{
    protected string $type = 'icon';

    protected array $props = [];

    public static function make(string $name = ''): static
    {
        $el = new static;
        if ($name !== '') {
            $el->props['name'] = $name;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['name'])) {
            $this->props['name'] = $attrs['name'];
        }
        if (isset($attrs['size'])) {
            $this->props['size'] = (float) $attrs['size'];
        }
        if (isset($attrs['color'])) {
            $this->props['color'] = $attrs['color'];
        }
    }

    protected function defaults(): array
    {
        return ['size' => 24, 'color' => '#333333'];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
