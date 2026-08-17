<?php

namespace Native\Mobile\Edge\Components\Native;

use Native\Mobile\Edge\NativeElementCollector;

class Text extends NativeBladeComponent
{
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
            // close also emits streaming-aware, like any <text>.
            NativeElementCollector::textOpen($data['attributes']->getAttributes());
            echo $data['slot']->toHtml();

            NativeElementCollector::textClose();

            return '';
        };
    }
}
