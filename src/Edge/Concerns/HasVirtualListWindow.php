<?php

namespace Native\Mobile\Edge\Concerns;

/**
 * State holder for `<native:virtual-list>`. Tracks the absolute index
 * range PHP should currently emit; native fires `on_window_change(from,
 * to)` as the visible range moves, the handler updates this state, and
 * the next render re-emits the slice.
 *
 * Single-list per screen for now — multi-list support would key windows
 * by element id; deferred to v2.
 */
trait HasVirtualListWindow
{
    /** Absolute index of the first row PHP should emit. */
    public int $virtualWindowFrom = 0;

    /**
     * Absolute index of the last row PHP should emit (inclusive). Default
     * 80-row window covers the first paint for any phone size + overscan
     * room for the user to scroll before native fires the first window
     * change. With hysteresis on the native side, smaller windows would
     * fire window-change immediately on first scroll — pre-budgeting the
     * window here skips that initial re-render.
     */
    public int $virtualWindowTo = 79;

    /**
     * The row the list was last asked to show, for the list's
     * `:auto-scroll-to`. Null until scrollVirtualListTo() is called.
     */
    public ?int $virtualScrollTo = null;

    public function setVirtualWindow(int $from, int $to): void
    {
        $this->virtualWindowFrom = max(0, $from);
        $this->virtualWindowTo = max($this->virtualWindowFrom, $to);
    }

    /**
     * Show absolute row $index: move the window so it starts a little above
     * the row, keeping its size, and remember the row for the list's
     * `:auto-scroll-to="$virtualScrollTo"`. The row is in the very render
     * that scrolls to it, so it draws in place of a placeholder.
     */
    public function scrollVirtualListTo(int $index): void
    {
        $index = max(0, $index);
        $size = max(0, $this->virtualWindowTo - $this->virtualWindowFrom);
        $from = max(0, $index - intdiv($size, 4));

        $this->setVirtualWindow($from, $from + $size);
        $this->virtualScrollTo = $index;
    }
}
