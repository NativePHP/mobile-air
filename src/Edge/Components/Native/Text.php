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
            $attrs = $data['attributes']->getAttributes();
            $text = NativeElementCollector::normalizeLeafText(
                $data['slot']->toHtml(),
                NativeElementCollector::whiteSpacePolicy($attrs)
            );
            if ($text !== '') {
                $attrs['text'] = $text;
            } else {
                $attrs = NativeElementCollector::applyTextWhiteSpace($attrs);
            }

            if (NativeElementCollector::isStreaming()) {
                NativeElementCollector::leafStreaming($this->elementType(), $attrs);
            } else {
                NativeElementCollector::leaf($this->elementType(), $attrs);
            }

            return '';
        };
    }
}
