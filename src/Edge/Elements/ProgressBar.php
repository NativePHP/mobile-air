<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class ProgressBar extends Element
{
    protected string $type = 'progress_bar';

    protected array $progressBarProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['value'])) {
            $this->value((float) $attrs['value']);
        }
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }
        if (isset($attrs['trackColor'])) {
            $this->trackColor($attrs['trackColor']);
        }
    }

    public function value(float $val): static
    {
        $this->progressBarProps['value'] = $val;

        return $this;
    }

    public function color(string $color): static
    {
        $this->progressBarProps['color'] = $color;

        return $this;
    }

    public function trackColor(string $color): static
    {
        $this->progressBarProps['track_color'] = $color;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->progressBarProps;
    }
}
