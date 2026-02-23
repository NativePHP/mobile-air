<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class ScrollView extends Element
{
    protected string $type = 'scroll_view';

    protected array $scrollProps = [];

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }

    public function horizontal(bool $value = true): static
    {
        $this->scrollProps['horizontal'] = $value;

        return $this;
    }

    public function showsIndicators(bool $value = true): static
    {
        $this->scrollProps['shows_indicators'] = $value;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->scrollProps;
    }
}
