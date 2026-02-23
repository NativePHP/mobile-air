<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class TabRow extends Element
{
    protected string $type = 'tab_row';

    protected array $tabRowProps = [];

    protected ?string $changeCallback = null;

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['selectedIndex'])) {
            $this->selectedIndex((int) $attrs['selectedIndex']);
        }
    }

    public function selectedIndex(int $index): static
    {
        $this->tabRowProps['selected_index'] = $index;

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->changeCallback = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->tabRowProps;

        if ($this->changeCallback !== null) {
            $props['on_change'] = $registry->register($this->changeCallback);
        }

        return $props;
    }
}
