<?php

namespace Native\Mobile\Edge\Components\Native;

use Native\Mobile\Edge\NativeElementCollector;

class Text extends NativeBladeComponent
{
    /**
     * Skips the base withAttributes emission, which also means this
     * component's frame opens AFTER its slot has rendered, unlike
     * containers whose element opens before the slot runs.
     */
    protected bool $handlesCollectorManually = true;

    protected function elementType(): string
    {
        return 'text';
    }

    public function render(): \Closure
    {
        return function (array $data) {
            // Run the same frame cycle as a precompiled <text> tag, so a
            // nested component becomes a run of its parent, inheriting
            // its whitespace mode, while a top-level one merges slot
            // and attribute text through the identical policy. The
            // close stays streaming-aware, like any <text>.
            //
            // Blade closed the slot-capture buffer before this closure
            // runs, so the echoed HTML is inert text and textOpen's
            // buffer handoff sees the enclosing frame, never the
            // slot's. The cycle depends on that ordering.
            NativeElementCollector::textOpen($data['attributes']->getAttributes());
            echo $data['slot']->toHtml();
            NativeElementCollector::textClose();

            return '';
        };
    }
}
