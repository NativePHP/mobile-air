<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Button extends Element
{
    protected string $type = 'button';

    protected array $buttonProps = [];

    public static function make(string $label = ''): static
    {
        $el = new static;
        if ($label !== '') {
            $el->buttonProps['label'] = $label;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['label'])) {
            $this->buttonProps['label'] = $attrs['label'];
        }
        if (isset($attrs['disabled'])) {
            $this->buttonProps['disabled'] = (bool) $attrs['disabled'];
        }
        // bg-* on a button sets the button tint color, not the node background
        if (isset($attrs['bg'])) {
            $this->buttonProps['color'] = $attrs['bg'];
        }
        if (isset($attrs['color'])) {
            $this->buttonProps['label_color'] = $attrs['color'];
        }
        if (isset($attrs['labelColor'])) {
            $this->buttonProps['label_color'] = $attrs['labelColor'];
        }
        if (isset($attrs['fontSize'])) {
            $this->buttonProps['font_size'] = (float) $attrs['fontSize'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->buttonProps;
    }
}
