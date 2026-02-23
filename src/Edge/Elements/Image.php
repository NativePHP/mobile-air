<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Image extends Element
{
    protected string $type = 'image';

    protected array $imageProps = [];

    public static function make(string $src = ''): static
    {
        $el = new static;

        if ($src !== '') {
            $el->imageProps['src'] = $src;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['src'])) {
            $this->imageProps['src'] = $attrs['src'];
        }
        if (isset($attrs['fit'])) {
            $this->fit((int) $attrs['fit']);
        }
        if (isset($attrs['tintColor'])) {
            $this->tintColor($attrs['tintColor']);
        }
    }

    public function fit(int $mode): static
    {
        $this->imageProps['fit'] = $mode;

        return $this;
    }

    public function tintColor(string $color): static
    {
        $this->imageProps['tint_color'] = $color;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->imageProps;
    }
}
