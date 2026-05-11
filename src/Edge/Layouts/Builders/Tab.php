<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Native\Mobile\Concerns\HasPlatformIcon;
use Native\Mobile\Edge\Elements\BottomNavItem;

/**
 * Fluent builder for a single bottom-tab-bar item.
 *
 * Two constructor styles:
 *   - `Tab::search($label, icon: ..., placeholder: ..., debounceMs: ...)`
 *     — iOS 26 floating Liquid Glass search capsule. No URL; the search
 *     experience is an iOS-side overlay driven by the active screen's
 *     `searchItems()` and/or `onSearchQuery()` methods.
 *   - `Tab::link($label, $url, icon: ...)` — URL-bound tab. BottomNavItem
 *     auto-wires the URL to a `replace` navigation event when tapped.
 *   - `Tab::action($label, icon: ...)->press('method')` — action tab.
 *     No URL, no navigation. Tapping fires the dev's press handler so
 *     they can do anything (open a sheet, focus a search field, run
 *     business logic). Use `Tab::search()` instead for the iOS 26
 *     floating-capsule treatment.
 *
 * Either form can be customised with `->press('method')` to override the
 * default URL-driven navigation with an arbitrary handler.
 *
 * Usage:
 *   Tab::link('Home', '/', icon: 'home')
 *   Tab::link('Profile', '/profile', icon: 'person')->badge('3')
 *   Tab::search('Search', icon: 'search', placeholder: '…')
 */
class Tab
{
    use HasPlatformIcon;

    private string $id;

    private string $label;

    private string $url;

    private ?string $badge = null;

    private ?string $badgeColor = null;

    private bool $news = false;

    private bool $active = false;

    private bool $search = false;

    private ?string $searchPlaceholder = null;

    private int $searchDebounceMs = 250;

    /** @var list<mixed>|null  Mixed shapes: string | array | Element (see SearchItem). */
    private ?array $searchItems = null;

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
    public static function link(
        string $label,
        string $url,
        ?string $icon = null,
    ): self {
        $tab = new self(
            id: strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)),
            label: $label,
            url: $url,
        );
        if ($icon !== null) {
            $tab->icon($icon);
        }

        return $tab;
    }

    /**
     * Action-only tab — no URL, no auto-navigation. Tap fires the press
     * handler set via `->press('method')`.
     */
    public static function action(
        string $label,
        ?string $icon = null,
    ): self {
        $tab = new self(
            id: strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)),
            label: $label,
            url: '',
        );
        if ($icon !== null) {
            $tab->icon($icon);
        }

        return $tab;
    }

    /**
     * Dedicated factory for the iOS 26 search-role tab (floating Liquid
     * Glass capsule). Has no URL — the search experience is an iOS-side
     * overlay driven by the active screen's `searchItems()` and/or
     * `onSearchQuery()` methods.
     *
     * Usage:
     *
     *     Tab::search('Search', icon: 'search',
     *         placeholder: 'Search articles, songs, people…',
     *         debounceMs: 200,
     *     )
     */
    public static function search(
        string $label,
        ?string $icon = null,
        ?string $placeholder = null,
        int $debounceMs = 250,
    ): self {
        $tab = new self(
            id: strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)),
            label: $label,
            url: '',
        );
        if ($icon !== null) {
            $tab->icon($icon);
        }
        $tab->search = true;
        $tab->searchPlaceholder = $placeholder;
        $tab->searchDebounceMs = $debounceMs;

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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function isSearchTab(): bool
    {
        return $this->search;
    }

    public function getSearchDebounceMs(): int
    {
        return $this->searchDebounceMs;
    }

    /** @return list<mixed>|null */
    public function getSearchItems(): ?array
    {
        return $this->searchItems;
    }

    /**
     * Inject the search corpus from the active screen. Called by the
     * framework during chrome wrapping; not part of the user-facing
     * fluent API. Items may be strings, arrays, or Element instances —
     * shape dispatch happens at serialization time via `SearchItem`.
     *
     * @param  list<mixed>  $items
     */
    public function setSearchItems(array $items): self
    {
        $this->searchItems = array_values($items);

        return $this;
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
        if (($icon = $this->resolvedIcon()) !== null) {
            $attrs['icon'] = $icon;
            if (($variant = $this->resolvedMaterialVariant()) !== null) {
                $attrs['material_variant'] = $variant;
            }
        }
        if ($this->badge !== null)       $attrs['badge']      = $this->badge;
        if ($this->badgeColor !== null)  $attrs['badgeColor'] = $this->badgeColor;
        if ($this->news)                 $attrs['news']       = true;
        if ($this->search)               $attrs['search']     = true;
        if ($this->searchPlaceholder !== null) {
            $attrs['search_placeholder'] = $this->searchPlaceholder;
        }
        $attrs['search_debounce_ms'] = $this->searchDebounceMs;

        $item->applyAttributes($attrs);

        // Raw items go through a dedicated setter, not via $attrs, so
        // they bypass `applyAttributes` (which only handles primitives)
        // and remain mixed types until `NativeRootTabs::resolveProps`
        // normalizes them through the registry-aware `SearchItem` DTO.
        if ($this->searchItems !== null) {
            $item->setRawSearchItems($this->searchItems);
        }

        // Wire custom press handler if set. BottomNavItem::resolveProps
        // skips its URL → `replace` auto-navigation when a press method
        // is already attached, so this cleanly overrides the default.
        if ($this->pressMethod !== null) {
            $item->onPress($this->pressMethod);
        }

        return $item;
    }
}
