<?php

namespace Native\Mobile\Edge\Components\Native;

use Native\Mobile\Edge\NativeElementCollector;

class ButtonGroup extends NativeBladeComponent
{
    protected bool $handlesCollectorManually = true;

    protected function elementType(): string
    {
        return 'button_group';
    }

    public function render(): \Closure
    {
        return function (array $data) {
            $attrs = $data['attributes']->getAttributes();

            NativeElementCollector::leaf($this->elementType(), $attrs);

            return '';
        };
    }
}
