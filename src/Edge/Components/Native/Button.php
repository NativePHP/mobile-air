<?php

namespace Native\Mobile\Edge\Components\Native;

use Native\Mobile\Edge\NativeElementCollector;

class Button extends NativeBladeComponent
{
    protected bool $handlesCollectorManually = true;

    protected function elementType(): string
    {
        return 'button';
    }

    public function render(): \Closure
    {
        return function (array $data) {
            $attrs = $data['attributes']->getAttributes();
            $slot = trim(html_entity_decode(strip_tags($data['slot']->toHtml()), ENT_QUOTES, 'UTF-8'));

            if ($slot !== '' && ! isset($attrs['label'])) {
                $attrs['label'] = $slot;
            }

            NativeElementCollector::leaf($this->elementType(), $attrs);

            return '';
        };
    }
}
