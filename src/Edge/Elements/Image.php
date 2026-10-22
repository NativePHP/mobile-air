<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Enums\ImageFit;
use Native\Mobile\Edge\ImageSource;

class Image extends Element
{
    protected string $type = 'image';

    protected array $imageProps = [];

    public static function make(string $src = ''): static
    {
        $el = new static;
        if ($src !== '') {
            $el->imageProps['src'] = ImageSource::forDevice($src);
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['src'])) {
            $this->imageProps['src'] = ImageSource::forDevice($attrs['src']);
        }
        if (isset($attrs['fit'])) {
            $this->fit($attrs['fit']);
        }
        if (isset($attrs['tintColor'])) {
            $this->tintColor($attrs['tintColor']);
        }
        if (isset($attrs['alt'])) {
            $this->alt($attrs['alt']);
        }
    }

    /**
     * Accessibility alt text. When set, screen readers announce the image
     * with this label; when absent, renderers treat the image as decorative
     * and hide it from VoiceOver/TalkBack.
     */
    public function alt(string $text): static
    {
        $this->imageProps['alt'] = $text;

        return $this;
    }

    /**
     * How the image fills its frame: an {@see ImageFit} case, its name
     * (`cover`, `contain`, `fill`, `none`) or its wire value. Anything else
     * is ignored and the renderer's default (contain) applies.
     */
    public function fit(int|string|ImageFit $mode): static
    {
        if (($resolved = ImageFit::parse($mode)) !== null) {
            $this->imageProps['fit'] = $resolved;
        }

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
