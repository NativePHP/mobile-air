<?php

namespace Native\Mobile\Edge\Elements;

use SupaNative\Core\Edge\CallbackRegistry;
use SupaNative\Core\Edge\Element;

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
            'edge-blur' => 'edgeBlur',
            'hide-on-push' => 'hideOnPush',
            'tab-presentation-id' => 'tabPresentationId',
            'tab-presentation-configuration' => 'tabPresentationConfiguration',
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

        // iOS 26's scroll edge effect under the bar: soft (the default),
        // hard or none. Anything else is dropped.
        $edgeBlur = BottomBar::edgeBlurStyle($attrs['edgeBlur'] ?? null);
        if ($edgeBlur !== null) {
            $this->props['edge_blur'] = $edgeBlur;
        }

        // Unset means the renderers' default (see TabBar::hideOnPush()).
        if (isset($attrs['hideOnPush'])) {
            $this->props['hide_on_push'] = filter_var($attrs['hideOnPush'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['tabPresentationId'])) {
            $this->props['tab_presentation_id'] = $attrs['tabPresentationId'];
        }

        if (isset($attrs['tabPresentationConfiguration'])) {
            $this->props['tab_presentation_configuration'] = $attrs['tabPresentationConfiguration'];
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
