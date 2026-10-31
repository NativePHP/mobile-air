<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\Icon;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Icon\IosSymbol;

class BottomNavItem extends Element
{
    protected string $type = 'bottom_nav_item';

    protected array $props = [];

    /**
     * Platform icon overrides — `:ios-icon="Ios::House"` /
     * `:android-icon="Android::Home"` (enum case or raw string), with the
     * plain `icon` attr as the cross-platform fallback. Resolved to the
     * single wire `icon` (+ `material_variant`) at serialization via
     * IconResolver, same contract as the `<icon>` element and the Tab
     * builder's ->icon(ios:, android:).
     */
    private IosSymbol|string|null $iosIcon = null;

    private AndroidSymbol|string|null $androidIcon = null;

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

    /**
     * Active-tab reselect handler, injected by `Tab::onReselect()`.
     * Kept off `$props` until `resolveProps` registers it, so it is
     * absent from the wire entirely when unused.
     */
    private ?string $reselectMethod = null;

    private ?string $reselectScrollTarget = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach ([
            'badge-color' => 'badgeColor',
            'material-variant' => 'material_variant',
            'search-placeholder' => 'search_placeholder',
            'search-debounce-ms' => 'search_debounce_ms',
        ] as $kebab => $canonical) {
            if (isset($attrs[$kebab]) && ! isset($attrs[$canonical])) {
                $attrs[$canonical] = $attrs[$kebab];
            }
        }

        foreach (['id', 'icon', 'material_variant', 'url', 'label', 'badge', 'badgeColor'] as $key) {
            if (isset($attrs[$key])) {
                $snakeKey = strtolower(preg_replace('/[A-Z]/', '_$0', $key));
                $this->props[$snakeKey] = $attrs[$key];
            }
        }

        // `:ios-icon` / `:android-icon` (or the `<icon>`-style `:ios` /
        // `:android` shorthand) — precompiler keeps names verbatim, so
        // accept kebab and camel forms.
        $this->iosIcon = $attrs['ios-icon'] ?? $attrs['iosIcon'] ?? $attrs['ios'] ?? $this->iosIcon;
        $this->androidIcon = $attrs['android-icon'] ?? $attrs['androidIcon'] ?? $attrs['android'] ?? $this->androidIcon;

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

    /** Whether this tab is currently marked active (highlighted). */
    public function isActive(): bool
    {
        return (bool) ($this->props['active'] ?? false);
    }

    /**
     * Toggle the active highlight. Used by `TabBar::highlight()` when the
     * bar was reconstructed from an inline `<native:bottom-nav>` and no
     * item carried an explicit `active` attribute.
     */
    public function setActive(bool $active): static
    {
        $this->props['active'] = $active;

        return $this;
    }

    /**
     * Handler for a tap on the already-active tab (Android). Serialized
     * as an `on_reselect` prop carrying a registered callback id — the
     * PRESS event transports it — plus an optional
     * `reselect_scroll_target` prop naming a scroll container registered
     * in the Android `NativeScrollTargetRegistry`. When a target is set,
     * native scrolls it to top first and then invokes PHP once the
     * scroll completes — unless it is already at exact top (first item
     * AND zero pixel offset), in which case PHP is invoked immediately.
     * Never suppresses the URL tab's auto-wired `on_press` navigation.
     */
    public function onReselect(string $method, ?string $scrollTarget = null): static
    {
        $this->reselectMethod = $method;
        $this->reselectScrollTarget = $scrollTarget;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        // Resolve the platform icon triple down to the single wire icon.
        // A bound enum in the shared `icon` slot is tolerated too.
        $shared = $this->props['icon'] ?? null;
        if ($shared instanceof \BackedEnum) {
            $shared = (string) $shared->value;
        }
        $resolved = IconResolver::resolve($shared, $this->iosIcon, $this->androidIcon);
        if ($resolved['icon'] !== null) {
            $this->props['icon'] = $resolved['icon'];
            if ($resolved['variant'] !== null && ! isset($this->props['material_variant'])) {
                $this->props['material_variant'] = $resolved['variant'];
            }
        }
        // An explicit `material-variant` attr means nothing to a custom icon.
        if (Icon::isCustom($this->props['icon'] ?? null)) {
            unset($this->props['material_variant']);
        }

        // Search-role tabs are iOS-owned (the floating Liquid Glass
        // capsule's `.searchable` lives entirely on the iOS side; PHP
        // doesn't host a destination for them). Skipping auto-navigate
        // means tapping the capsule doesn't unmount the active screen
        // — PHP state stays on Home (or whatever), and iOS handles the
        // search UI locally with items injected from the active
        // screen's `searchItems()`.
        if (
            ! empty($this->props['url'])
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

        // Active-tab reselect handler — rides the props dict like
        // `on_double_tap`, so no wire-format node field changes and the
        // id alone routes the PRESS event to the handler. Registered
        // fresh each resolve; omitted entirely when no handler is set.
        if ($this->reselectMethod !== null) {
            $this->props['on_reselect'] = $registry->register($this->reselectMethod);
            if ($this->reselectScrollTarget !== null) {
                $this->props['reselect_scroll_target'] = $this->reselectScrollTarget;
            }
        }

        return $this->props;
    }
}
