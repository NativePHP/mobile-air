<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Native\Mobile\Edge\Elements\BottomNavItem;

/**
 * Fluent builder for a single bottom-tab-bar item.
 *
 * Two constructor styles:
 *   - `Tab::link($label, $url, icon: ...)` — URL-bound tab. BottomNavItem
 *     auto-wires the URL to a `replace` navigation event when tapped.
 *   - `Tab::action($label, icon: ...)->press('method')` — action tab.
 *     No URL, no navigation. Tapping fires the dev's press handler so
 *     they can do anything (open a sheet, focus a search field, run
 *     business logic). Combine with `->search()` for the iOS 26
 *     floating-capsule treatment.
 *
 * Either form can be customised with `->press('method')` to override the
 * default URL-driven navigation with an arbitrary handler.
 *
 * Usage:
 *   Tab::link('Home', '/', icon: 'home')
 *   Tab::link('Profile', '/profile', icon: 'person')->badge('3')
 *   Tab::action('Search', icon: 'search')->search()->press('openSearch')
 */
class Tab
{
    private string $id;

    private string $label;

    private string $url;

    private ?string $icon = null;

    private ?string $badge = null;

    private ?string $badgeColor = null;

    private bool $news = false;

    private bool $active = false;

    private bool $search = false;

    private ?string $pressMethod = null;

    private function __construct(string $id, string $label, string $url)
    {
        $this->id = $id;
        $this->label = $label;
        $this->url = $url;
    }

    /**
     * Most common form: a label, the url to navigate to, and an icon.
     * The id defaults to the label slugified.
     */
    public static function link(string $label, string $url, ?string $icon = null): self
    {
        $tab = new self(
            id: strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)),
            label: $label,
            url: $url,
        );
        if ($icon !== null) {
            $tab->icon = $icon;
        }

        return $tab;
    }

    /**
     * Action-only tab — no URL, no auto-navigation. Tap fires the press
     * handler set via `->press('method')`. Useful with `->search()` for
     * the iOS 26 floating-capsule treatment when the tap should open a
     * search sheet instead of navigating to a separate route.
     */
    public static function action(string $label, ?string $icon = null): self
    {
        $tab = new self(
            id: strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)),
            label: $label,
            url: '',
        );
        if ($icon !== null) {
            $tab->icon = $icon;
        }

        return $tab;
    }

    /**
     * Override the default URL-driven `replace` navigation with a custom
     * press handler. Works with both `Tab::link()` (overrides nav) and
     * `Tab::action()` (sole tap behavior).
     */
    public function press(string $method): self
    {
        $this->pressMethod = $method;

        return $this;
    }

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function badge(string $badge, ?string $color = null): self
    {
        $this->badge = $badge;
        $this->badgeColor = $color;

        return $this;
    }

    public function news(bool $news = true): self
    {
        $this->news = $news;

        return $this;
    }

    public function active(bool $active = true): self
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Mark this tab as the "search" tab. On iOS 26+ the system renders it
     * as a separate floating Liquid Glass capsule beside the main tab bar
     * (the pattern used by Apple's own Photos / Music / Mail apps). On
     * older iOS the role is a no-op visually but still semantically tagged.
     */
    public function search(bool $search = true): self
    {
        $this->search = $search;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function toElement(): BottomNavItem
    {
        $item = BottomNavItem::make();

        $attrs = [
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->url,
            'active' => $this->active,
        ];
        if ($this->icon !== null)        $attrs['icon']       = $this->icon;
        if ($this->badge !== null)       $attrs['badge']      = $this->badge;
        if ($this->badgeColor !== null)  $attrs['badgeColor'] = $this->badgeColor;
        if ($this->news)                 $attrs['news']       = true;
        if ($this->search)               $attrs['search']     = true;

        $item->applyAttributes($attrs);

        // Wire custom press handler if set. BottomNavItem::resolveProps
        // skips its URL → `replace` auto-navigation when a press method
        // is already attached, so this cleanly overrides the default.
        if ($this->pressMethod !== null) {
            $item->onPress($this->pressMethod);
        }

        return $item;
    }
}
