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

    protected function layoutDefaults(): array
    {
        return [
            'overflow' => 2, // scroll — tells Yoga not to constrain children on scroll axis
        ];
    }

    public function horizontal(bool $value = true): static
    {
        $this->scrollProps['horizontal'] = $value;
        $this->scrollProps['axis'] = $value ? 'horizontal' : 'vertical';

        if ($value) {
            // Tell Yoga the main axis is horizontal so overflow:scroll applies to width
            $this->layout['flex_direction'] = 1; // row
        }

        return $this;
    }

    /**
     * Enable scrolling on BOTH axes — 2D pan. Use for content that's
     * larger than the viewport in both dimensions (e.g. a large image as
     * a pannable backdrop, or a zoomable canvas).
     *
     * Authors must give the inner content explicit dimensions larger than
     * the scroll-view's viewport for there to be anything to pan to. Flex
     * layout is bypassed in this mode — children render at their declared
     * frames and SwiftUI's `ScrollView([.horizontal, .vertical])` handles
     * the panning.
     */
    public function both(): static
    {
        $this->scrollProps['axis'] = 'both';

        // Don't set flex_direction — 2D mode bypasses flex on its content.
        // The iOS renderer wraps children in a ZStack which respects each
        // child's declared frame instead of forcing a 1D layout.

        return $this;
    }

    public function showsIndicators(bool $value = true): static
    {
        $this->scrollProps['shows_indicators'] = $value;

        return $this;
    }

    public function autoScrollTo(int $index): static
    {
        $this->scrollProps['auto_scroll_to'] = $index;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->scrollProps;
    }
}
