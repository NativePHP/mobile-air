<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Button extends Element
{
    protected string $type = 'button';

    protected array $buttonProps = [];

    protected ?string $pressCallback = null;

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

        if (isset($attrs['bg'])) {
            $this->color($attrs['bg']);
        }

        if (isset($attrs['color'])) {
            if (isset($attrs['bg'])) {
                $this->labelColor($attrs['color']);
            } else {
                $this->color($attrs['color']);
            }
        }

        if (isset($attrs['labelColor'])) {
            $this->labelColor($attrs['labelColor']);
        }

        if (isset($attrs['fontSize'])) {
            $this->fontSize((float) $attrs['fontSize']);
        }

        if (! empty($attrs['disabled'])) {
            $this->disabled();
        }
    }

    public function onPress(string $method): static
    {
        $this->pressCallback = $method;

        return $this;
    }

    public function disabled(bool $value = true): static
    {
        $this->buttonProps['disabled'] = $value;

        return $this;
    }

    public function color(string $color): static
    {
        $this->buttonProps['color'] = $color;

        return $this;
    }

    public function labelColor(string $color): static
    {
        $this->buttonProps['label_color'] = $color;

        return $this;
    }

    public function fontSize(float $size): static
    {
        $this->buttonProps['font_size'] = $size;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->buttonProps;

        if ($this->pressCallback !== null) {
            $props['on_press'] = $registry->register($this->pressCallback);
        }

        return $props;
    }
}
