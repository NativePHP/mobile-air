<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Line extends Element
{
    protected string $type = 'line';

    protected array $lineProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['from'])) {
            $parts = explode(',', $attrs['from']);
            if (count($parts) === 2) {
                $this->lineProps['from_x'] = (float) trim($parts[0]);
                $this->lineProps['from_y'] = (float) trim($parts[1]);
            }
        }
        if (isset($attrs['to'])) {
            $parts = explode(',', $attrs['to']);
            if (count($parts) === 2) {
                $this->lineProps['to_x'] = (float) trim($parts[0]);
                $this->lineProps['to_y'] = (float) trim($parts[1]);
            }
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->lineProps;
    }
}
