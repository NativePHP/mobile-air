<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Badge extends Element
{
    protected string $type = 'badge';

    protected array $badgeProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['count'])) {
            $this->count((int) $attrs['count']);
        }
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }
        if (isset($attrs['textColor'])) {
            $this->textColor($attrs['textColor']);
        }
    }

    public function count(int $count): static
    {
        $this->badgeProps['count'] = $count;

        return $this;
    }

    public function color(string $color): static
    {
        $this->badgeProps['color'] = $color;

        return $this;
    }

    public function textColor(string $color): static
    {
        $this->badgeProps['text_color'] = $color;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->badgeProps;
    }
}
