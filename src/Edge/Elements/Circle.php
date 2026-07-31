<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Circle extends Element
{
    protected string $type = 'circle';

    protected array $circleProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['left'])) {
            $this->circleProps['left'] = (float) $attrs['left'];
        }
        if (isset($attrs['top'])) {
            $this->circleProps['top'] = (float) $attrs['top'];
        }
    }

    protected function styleDefaults(): array
    {
        return ['border_radius' => 9999];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->circleProps;
    }
}
