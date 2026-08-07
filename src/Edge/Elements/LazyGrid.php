<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Self-scrolling grid that only materializes the cells currently in (or
 * adjacent to) the viewport.
 *
 * Maps to SwiftUI `ScrollView { LazyVGrid }` on iOS and Compose
 * `LazyVerticalGrid` on Android. Both platforms only build/lay-out the
 * visible rows — child cells off-screen are deferred until they scroll
 * into view. Use this in place of `<native:scroll-view>` wrapping a
 * manually-chunked `<native:row>` grid whenever the cell count is large
 * enough to be felt at parse / layout time (rule of thumb: ~50+ cells).
 *
 * Each child becomes one grid cell. Cells fill their column width by
 * default and size to their intrinsic height — wrap in `<native:column>`
 * with explicit sizing if you need uniform cell heights.
 *
 *   <native:lazy-grid :columns="4" :gap="12">
 *
 *       @foreach ($icons as $icon)
 *           <native:column class="items-center gap-1 p-3 rounded-lg">
 *               <native:icon :ios="$icon" :size="28" />
 *           </native:column>
 *
 *       @endforeach
 *   </native:lazy-grid>
 *
 * Set `horizontal: true` to flip orientation: rows become the cross axis,
 * `columns` is now the number of fixed-height rows, and the grid scrolls
 * horizontally (SwiftUI `LazyHGrid` / Compose `LazyHorizontalGrid`).
 */
class LazyGrid extends Element
{
    protected string $type = 'lazy_grid';

    protected array $gridProps = ['columns' => 2];

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['columns'])) {
            $this->columns((int) $attrs['columns']);
        }
        if (isset($attrs['gap'])) {
            $this->gap((float) $attrs['gap']);
        }
        if (isset($attrs['horizontal'])) {
            $this->horizontal((bool) $attrs['horizontal']);
        }
        if (isset($attrs['showsIndicators']) || isset($attrs['shows-indicators'])) {
            $this->showsIndicators((bool) ($attrs['showsIndicators'] ?? $attrs['shows-indicators']));
        }
    }

    public function columns(int $count): static
    {
        $this->gridProps['columns'] = max(1, $count);

        return $this;
    }

    /**
     * Spacing applied to BOTH axes (between rows and between columns).
     * Match the parent column's `gap-N` convention if you want flush
     * alignment with surrounding content.
     */
    public function gap(float $gap): static
    {
        $this->gridProps['gap'] = $gap;

        return $this;
    }

    public function horizontal(bool $value = true): static
    {
        $this->gridProps['horizontal'] = $value;

        return $this;
    }

    /**
     * Same contract as scroll-view, but the grid's historical default is
     * HIDDEN — pass true to opt long grids into a scroll-position cue.
     * iOS-only in effect: Compose's lazy grids draw no indicators anyway.
     */
    public function showsIndicators(bool $value = true): static
    {
        $this->gridProps['shows_indicators'] = $value;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->gridProps;
    }
}
