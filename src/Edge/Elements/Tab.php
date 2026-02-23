<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Tab extends Element
{
    protected string $type = 'tab';

    protected array $tabProps = [];

    public static function make(string $label = ''): static
    {
        $el = new static;
        if ($label !== '') {
            $el->tabProps['label'] = $label;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['label'])) {
            $this->tabProps['label'] = $attrs['label'];
        }
        if (isset($attrs['icon'])) {
            $this->icon($attrs['icon']);
        }
    }

    public function icon(string $icon): static
    {
        $this->tabProps['icon'] = $icon;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->tabProps;
    }
}
