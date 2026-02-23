<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class ListItem extends Element
{
    protected string $type = 'list_item';

    protected array $listItemProps = [];

    public static function make(string $headline = ''): static
    {
        $el = new static;
        if ($headline !== '') {
            $el->listItemProps['headline'] = $headline;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['headline'])) {
            $this->listItemProps['headline'] = $attrs['headline'];
        }
        if (isset($attrs['supporting'])) {
            $this->supporting($attrs['supporting']);
        }
        if (isset($attrs['overline'])) {
            $this->overline($attrs['overline']);
        }
        if (isset($attrs['leadingIcon'])) {
            $this->leadingIcon($attrs['leadingIcon']);
        }
        if (isset($attrs['trailingIcon'])) {
            $this->trailingIcon($attrs['trailingIcon']);
        }
        if (isset($attrs['headlineColor'])) {
            $this->headlineColor($attrs['headlineColor']);
        }
        if (isset($attrs['supportingColor'])) {
            $this->supportingColor($attrs['supportingColor']);
        }
    }

    public function supporting(string $text): static
    {
        $this->listItemProps['supporting'] = $text;

        return $this;
    }

    public function overline(string $text): static
    {
        $this->listItemProps['overline'] = $text;

        return $this;
    }

    public function leadingIcon(string $icon): static
    {
        $this->listItemProps['leading_icon'] = $icon;

        return $this;
    }

    public function trailingIcon(string $icon): static
    {
        $this->listItemProps['trailing_icon'] = $icon;

        return $this;
    }

    public function headlineColor(string $color): static
    {
        $this->listItemProps['headline_color'] = $color;

        return $this;
    }

    public function supportingColor(string $color): static
    {
        $this->listItemProps['supporting_color'] = $color;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->listItemProps;
    }
}
