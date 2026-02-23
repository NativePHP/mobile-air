<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Carousel extends Element
{
    protected string $type = 'carousel';

    protected array $carouselProps = [];

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['variant'])) {
            $this->variant($attrs['variant']);
        }
        if (isset($attrs['itemWidth']) || isset($attrs['item-width'])) {
            $this->itemWidth((float) ($attrs['itemWidth'] ?? $attrs['item-width']));
        }
        if (isset($attrs['itemSpacing']) || isset($attrs['item-spacing'])) {
            $this->itemSpacing((float) ($attrs['itemSpacing'] ?? $attrs['item-spacing']));
        }
    }

    public function variant(string $variant): static
    {
        $this->carouselProps['variant'] = $variant;

        return $this;
    }

    public function itemWidth(float $width): static
    {
        $this->carouselProps['item_width'] = $width;

        return $this;
    }

    public function itemSpacing(float $spacing): static
    {
        $this->carouselProps['item_spacing'] = $spacing;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->carouselProps;
    }
}
