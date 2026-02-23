<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Card extends Element
{
    protected string $type = 'card';

    protected array $cardProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['variant'])) {
            $this->variant((int) $attrs['variant']);
        }
    }

    public function variant(int $variant): static
    {
        $this->cardProps['variant'] = $variant;

        return $this;
    }

    public function filled(): static
    {
        $this->cardProps['variant'] = 0;

        return $this;
    }

    public function outlined(): static
    {
        $this->cardProps['variant'] = 1;

        return $this;
    }

    public function elevated(): static
    {
        $this->cardProps['variant'] = 2;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->cardProps;
    }
}
