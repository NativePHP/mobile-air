<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class BottomNav extends Element
{
    protected string $type = 'bottom_nav';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        // Blade markup arrives kebab-cased; normalize to the camelCase
        // keys the builder path (TabBar::toElement) already sends.
        foreach ([
            'label-visibility' => 'labelVisibility',
            'active-color' => 'activeColor',
            'background-color' => 'backgroundColor',
            'text-color' => 'textColor',
            'font-name' => 'fontName',
            'minimize-on-scroll' => 'minimizeOnScroll',
        ] as $kebab => $camel) {
            if (isset($attrs[$kebab]) && ! isset($attrs[$camel])) {
                $attrs[$camel] = $attrs[$kebab];
            }
        }

        if (isset($attrs['dark'])) {
            $this->props['dark'] = filter_var($attrs['dark'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['labelVisibility'])) {
            $this->props['label_visibility'] = $attrs['labelVisibility'];
        }

        if (isset($attrs['activeColor'])) {
            $this->props['active_color'] = $attrs['activeColor'];
        }

        if (isset($attrs['backgroundColor'])) {
            $this->props['background_color'] = $attrs['backgroundColor'];
        }

        if (isset($attrs['textColor'])) {
            $this->props['text_color'] = $attrs['textColor'];
        }

        if (isset($attrs['fontName'])) {
            $this->props['font_name'] = $attrs['fontName'];
        }

        if (isset($attrs['minimizeOnScroll'])) {
            $this->props['minimize_on_scroll'] = filter_var($attrs['minimizeOnScroll'], FILTER_VALIDATE_BOOLEAN);
        }

        if (! empty($attrs['custom'])) {
            $this->markCustomChrome();
        }

        $this->props['id'] = 'bottom_nav';
    }

    /**
     * The collected snake_case props, for TabBar::fromElement() to
     * reconstruct a builder from an inline `<native:bottom-nav>`.
     */
    public function getRawProps(): array
    {
        return $this->props;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
