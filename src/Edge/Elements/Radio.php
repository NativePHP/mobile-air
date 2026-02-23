<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Radio extends Element
{
    protected string $type = 'radio';

    protected array $radioProps = [];

    public static function make(string $value = ''): static
    {
        $el = new static;
        if ($value !== '') {
            $el->radioProps['value'] = $value;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['radioValue'])) {
            $this->radioProps['value'] = $attrs['radioValue'];
        }
        if (isset($attrs['label'])) {
            $this->label($attrs['label']);
        }
        if (isset($attrs['labelColor'])) {
            $this->labelColor($attrs['labelColor']);
        }
        if (! empty($attrs['disabled'])) {
            $this->disabled();
        }
    }

    public function label(string $label): static
    {
        $this->radioProps['label'] = $label;

        return $this;
    }

    public function labelColor(string $color): static
    {
        $this->radioProps['label_color'] = $color;

        return $this;
    }

    public function disabled(bool $value = true): static
    {
        $this->radioProps['disabled'] = $value;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->radioProps;
    }
}
