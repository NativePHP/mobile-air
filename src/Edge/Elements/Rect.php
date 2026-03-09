<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Rect extends Element
{
    protected string $type = 'rect';

    protected array $rectProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['left'])) {
            $this->rectProps['left'] = (float) $attrs['left'];
        }
        if (isset($attrs['top'])) {
            $this->rectProps['top'] = (float) $attrs['top'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->rectProps;
    }
}
