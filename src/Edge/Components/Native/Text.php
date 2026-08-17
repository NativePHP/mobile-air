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
            // Merge slot and attribute text through the collector's shared
            // whitespace policy so this component and the precompiled
            // <text> tag resolve their text prop identically.
            $attrs = NativeElementCollector::mergeSlotText(
                $data['attributes']->getAttributes(),
                $data['slot']->toHtml()
            );

            if (NativeElementCollector::isStreaming()) {
                NativeElementCollector::leafStreaming($this->elementType(), $attrs);
            } else {
                NativeElementCollector::leaf($this->elementType(), $attrs);
            }

            return '';
        };
    }
}
