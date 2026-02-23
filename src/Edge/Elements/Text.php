<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Text extends Element
{
    protected string $type = 'text';

    protected array $textProps = [];

    public static function make(string $text = ''): static
    {
        $el = new static;

        if ($text !== '') {
            $el->textProps['text'] = $text;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['text'])) {
            $this->textProps['text'] = $attrs['text'];
        }
        if (isset($attrs['fontSize'])) {
            $this->fontSize((float) $attrs['fontSize']);
        }
        if (isset($attrs['fontWeight'])) {
            $this->fontWeight((int) $attrs['fontWeight']);
        }
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }
        if (isset($attrs['textAlign'])) {
            $this->textAlign((int) $attrs['textAlign']);
        }
        if (isset($attrs['maxLines'])) {
            $this->maxLines((int) $attrs['maxLines']);
        }
    }

    public function fontSize(float $size): static
    {
        $this->textProps['font_size'] = $size;

        return $this;
    }

    public function fontWeight(int $weight): static
    {
        $this->textProps['font_weight'] = $weight;

        return $this;
    }

    public function bold(): static
    {
        $this->textProps['font_weight'] = 7;

        return $this;
    }

    public function color(string $color): static
    {
        $this->textProps['color'] = $color;

        return $this;
    }

    public function textAlign(int $align): static
    {
        $this->textProps['text_align'] = $align;

        return $this;
    }

    public function maxLines(int $lines): static
    {
        $this->textProps['max_lines'] = $lines;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->textProps;
    }
}
