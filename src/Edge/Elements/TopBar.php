<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class TopBar extends Element
{
    protected string $type = 'top_bar';

    protected array $props = [];

    /**
     * What a drawn bar shows for the back button when no `back` /
     * `show-navigation-icon` attribute decided it. Not an attribute: it
     * never shows up in getRawProps(), so an unset back button stays
     * unset for NavBar::fromElement().
     */
    private ?bool $defaultBack = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        // Blade markup arrives kebab-cased; normalize to the camelCase
        // keys the builder path (NavBar::toElement) already sends.
        foreach ([
            'background-color' => 'backgroundColor',
            'text-color' => 'textColor',
            'font-name' => 'fontName',
            'show-navigation-icon' => 'showNavigationIcon',
            'display-mode' => 'displayMode',
            'scroll-behavior' => 'scrollBehavior',
            'search-placeholder' => 'searchPlaceholder',
            'search-on-query' => 'searchOnQuery',
            'search-debounce-ms' => 'searchDebounceMs',
        ] as $kebab => $camel) {
            if (isset($attrs[$kebab]) && ! isset($attrs[$camel])) {
                $attrs[$camel] = $attrs[$kebab];
            }
        }

        foreach (['title', 'subtitle', 'backgroundColor', 'textColor', 'fontName', 'displayMode', 'scrollBehavior', 'searchPlaceholder', 'searchOnQuery'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = $attrs[$key];
            }
        }

        if (isset($attrs['showNavigationIcon'])) {
            $this->props['show_navigation_icon'] = filter_var($attrs['showNavigationIcon'], FILTER_VALIDATE_BOOLEAN);
        }

        // `back` is the builder-facing name (NavBar::back()); accept it as
        // a blade alias for show-navigation-icon. Either one is only
        // written when present, so a bar without it leaves the back button
        // to the screen's route (see NavBar).
        if (isset($attrs['back']) && ! isset($this->props['show_navigation_icon'])) {
            $this->props['show_navigation_icon'] = filter_var($attrs['back'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['elevation'])) {
            $this->props['elevation'] = (int) $attrs['elevation'];
        }

        if (isset($attrs['searchDebounceMs'])) {
            $this->props['search_debounce_ms'] = (int) $attrs['searchDebounceMs'];
        }

        if (! empty($attrs['custom'])) {
            $this->markCustomChrome();
        }
    }

    /**
     * The collected snake_case props, for NavBar::fromElement() to
     * reconstruct a builder from an inline `<native:top-bar>`.
     */
    public function getRawProps(): array
    {
        return $this->props;
    }

    /**
     * Fill in the back button for drawing when no attribute set it.
     * NavBar::toElement() passes the bar's default from the screen's
     * route here, so the drawn bar always says one way or the other.
     */
    public function defaultBack(?bool $show): static
    {
        $this->defaultBack = $show;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->props;

        if ($this->defaultBack !== null && ! array_key_exists('show_navigation_icon', $props)) {
            $props['show_navigation_icon'] = $this->defaultBack;
        }

        return $props;
    }
}
