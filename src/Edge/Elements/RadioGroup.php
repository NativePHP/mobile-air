<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class RadioGroup extends Element
{
    protected string $type = 'radio_group';

    protected array $radioGroupProps = [];

    protected ?string $changeCallback = null;

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['value'])) {
            $this->value($attrs['value']);
        }
    }

    public function value(string $selectedValue): static
    {
        $this->radioGroupProps['value'] = $selectedValue;

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->changeCallback = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->radioGroupProps;

        if ($this->changeCallback !== null) {
            $props['on_change'] = $registry->register($this->changeCallback);
        }

        return $props;
    }
}
