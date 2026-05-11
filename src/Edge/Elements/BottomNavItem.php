<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class BottomNavItem extends Element
{
    protected string $type = 'bottom_nav_item';

    protected array $props = [];

    /**
     * Raw search-items from the active screen's `searchItems()` /
     * `onSearchQuery()` return. Stored separately from `$props` because
     * they're mixed-type (string | array | Element) and need
     * registry-aware normalization (per-item callback registration) at
     * the `NativeRootTabs::resolveProps` step. They never appear in the
     * serialized BNI props.
     *
     * @var list<mixed>|null
     */
    private ?array $rawSearchItems = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach (['id', 'icon', 'material_variant', 'url', 'label', 'badge', 'badgeColor'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = $attrs[$key];
            }
        }

        if (isset($attrs['active'])) {
            $this->props['active'] = filter_var($attrs['active'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['news'])) {
            $this->props['news'] = filter_var($attrs['news'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($attrs['search'])) {
            $this->props['search'] = filter_var($attrs['search'], FILTER_VALIDATE_BOOLEAN);
        }

        // Optional placeholder for the iOS 26 floating-capsule search
        // field. NativeRootTabs hoists this onto the root as a fallback
        // `nav_search_placeholder` so SwiftUI's `.searchable` modifier
        // can attach at the TabView level even when the active screen's
        // own NavBarOptions::searchBar() didn't supply one.
        if (isset($attrs['search_placeholder'])) {
            $this->props['search_placeholder'] = $attrs['search_placeholder'];
        }

        // Debounce hint for dynamic-mode TEXT_CHANGE events. Hoisted
        // onto `nav_search_debounce_ms` at the tabs root.
        if (isset($attrs['search_debounce_ms'])) {
            $this->props['search_debounce_ms'] = (int) $attrs['search_debounce_ms'];
        }
    }

    public function isSearchTab(): bool
    {
        return (bool) ($this->props['search'] ?? false);
    }

    public function getSearchPlaceholder(): ?string
    {
        return $this->props['search_placeholder'] ?? null;
    }

    public function getSearchDebounceMs(): int
    {
        return (int) ($this->props['search_debounce_ms'] ?? 250);
    }

    /**
     * Injected by `Tab::toElement()`. Items are raw (mixed types);
     * `NativeRootTabs::resolveProps` normalizes them through
     * `SearchItem` before they reach the wire.
     *
     * @param  list<mixed>  $items
     */
    public function setRawSearchItems(array $items): self
    {
        $this->rawSearchItems = array_values($items);

        return $this;
    }

    /** @return list<mixed>|null */
    public function getRawSearchItems(): ?array
    {
        return $this->rawSearchItems;
    }

    public function getUrl(): string
    {
        return (string) ($this->props['url'] ?? '');
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        // Search-role tabs are iOS-owned (the floating Liquid Glass
        // capsule's `.searchable` lives entirely on the iOS side; PHP
        // doesn't host a destination for them). Skipping auto-navigate
        // means tapping the capsule doesn't unmount the active screen
        // — PHP state stays on Home (or whatever), and iOS handles the
        // search UI locally with items injected from the active
        // screen's `searchItems()`.
        if (
            !empty($this->props['url'])
            && $this->pressMethod === null
            && empty($this->props['search'])
        ) {
            // Tab taps should `replace` the current screen, not push onto
            // the stack. Otherwise tapping Chats → Friends → Profile builds
            // up a 4-deep stack, and the framework back chevron pops one
            // tab at a time instead of returning to where the user came
            // from before entering the tabs section.
            $this->setNavigateConfig([
                'type' => 'replace',
                'uri' => $this->props['url'],
                'data' => [],
                'transition' => 'none',
            ]);
        }

        return $this->props;
    }
}
