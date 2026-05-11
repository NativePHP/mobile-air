<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Sentinel element emitted by `wrapWithChrome` when a layout opts into
 * native chrome via `NativeLayout::usesNativeChrome() = true` and a
 * TabBar is present (with or without a NavBar — when both are present,
 * the NavBar config is folded into the tabs root since each tab hosts
 * its own NavigationStack natively).
 *
 * Carries the TabBar config as flat props (dark, active_color,
 * background_color, text_color, label_visibility) plus the tab items
 * themselves as `bottom_nav_item` children. The active tab's screen
 * content is appended as the final child; inactive tabs render as
 * empty placeholders until the user navigates to them (PHP only ever
 * has one tab's tree alive at a time).
 *
 * When a NavBar is also present, its config is folded in via
 * `nav_*` prefixed props (nav_title, nav_subtitle, …) plus
 * `top_bar_action` children — same routing pattern as NavigationStack.
 *
 * iOS / Android renderers detect this element type and route to native
 * `TabView` / `Scaffold(bottomBar = NavigationBar)` chrome.
 */
class NativeRootTabs extends Element
{
    protected string $type = 'native_root_tabs';

    protected array $props = [];

    /**
     * Method name on the active screen that handles dynamic-mode
     * search queries (returns `array` of items per keystroke). Set by
     * `wrapWithNativeChrome` when the active component overrides
     * `onSearchQuery`. Registered with kind `search_query` so
     * `NativeComponent::dispatch` knows to capture the return value
     * and stash it as the next publish's `nav_search_items`.
     */
    private ?string $navSearchOnQueryMethod = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        // TabBar config
        if (isset($attrs['dark']))            $this->props['dark']             = (bool) $attrs['dark'];
        if (isset($attrs['activeColor']))     $this->props['active_color']     = $attrs['activeColor'];
        if (isset($attrs['backgroundColor'])) $this->props['background_color'] = $attrs['backgroundColor'];
        if (isset($attrs['textColor']))       $this->props['text_color']       = $attrs['textColor'];
        if (isset($attrs['labelVisibility'])) $this->props['label_visibility'] = $attrs['labelVisibility'];
        if (isset($attrs['minimizeOnScroll'])) $this->props['minimize_on_scroll'] = (bool) $attrs['minimizeOnScroll'];

        // Optional folded NavBar config (when this layout supplies both bars).
        if (isset($attrs['navTitle']))           $this->props['nav_title']            = $attrs['navTitle'];
        if (isset($attrs['navSubtitle']))        $this->props['nav_subtitle']         = $attrs['navSubtitle'];
        if (isset($attrs['navBack']))            $this->props['nav_back']             = (bool) $attrs['navBack'];
        if (isset($attrs['navBackgroundColor'])) $this->props['nav_background_color'] = $attrs['navBackgroundColor'];
        if (isset($attrs['navTextColor']))       $this->props['nav_text_color']       = $attrs['navTextColor'];
        if (isset($attrs['navElevation']))       $this->props['nav_elevation']        = (int) $attrs['navElevation'];

        // The URI of the active tab's screen. The iOS bridge keys its
        // per-URI tree diff off this so tab-switch publishes reuse
        // unchanged subtree refs and don't trigger a full re-render.
        if (isset($attrs['currentUri']))      $this->props['current_uri']      = $attrs['currentUri'];

        // Per-screen tab-bar overrides folded in by `wrapWithNativeChrome`.
        // `hide_tab_bar` is the explicit signal renderers use to hide the
        // bottom nav on detail / pushed screens — replaces the earlier
        // URI-match heuristic.
        if (isset($attrs['hideTabBar']))      $this->props['hide_tab_bar']     = (bool) $attrs['hideTabBar'];
        if (isset($attrs['tabHighlight']))    $this->props['tab_highlight']    = $attrs['tabHighlight'];

        // Inline NavBar search field — Apple HIG / Expo pattern.
        // iOS attaches `.searchable` to the destination view; Android
        // shows an M3 search field in the top app bar slot.
        if (isset($attrs['navSearchPlaceholder'])) $this->props['nav_search_placeholder'] = $attrs['navSearchPlaceholder'];
        if (isset($attrs['navSearchOnQuery']))     $this->navSearchOnQueryMethod         = $attrs['navSearchOnQuery'];
        if (isset($attrs['navSearchDebounceMs']))  $this->props['nav_search_debounce_ms'] = (int) $attrs['navSearchDebounceMs'];

        // New screen-driven dynamic-mode handler (preferred over the
        // legacy NavBar-folded variant above). `wrapWithNativeChrome`
        // sets this when the active screen overrides `onSearchQuery`.
        if (isset($attrs['navSearchOnQueryMethod'])) {
            $this->navSearchOnQueryMethod = $attrs['navSearchOnQueryMethod'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        // Register the dynamic-mode query handler with kind `search_query`
        // so `NativeComponent::dispatch()` knows to capture its return
        // value into `$pendingSearchResults` instead of fire-and-forget.
        if ($this->navSearchOnQueryMethod !== null) {
            $this->props['nav_search_on_query'] = $registry->register(
                $this->navSearchOnQueryMethod,
                kind: 'search_query'
            );
        }

        // Hoist the search-role tab's config up to the root and adopt
        // its items as `search_item` children of this root. Wire format
        // can't carry arbitrary arrays-of-dicts as a prop, so items go
        // through the regular tree path — one node per item, dispatched
        // by `kind` in the renderer. Items are inserted just after the
        // existing children so the search-role BottomNavItem stays
        // adjacent to its corpus in the tree (helpful for diff
        // locality, and means iOS can filter `children.type ==
        // "search_item"` without scanning the whole subtree).
        $searchItemsToAdopt = [];
        foreach ($this->children as $child) {
            if (! ($child instanceof BottomNavItem) || ! $child->isSearchTab()) {
                continue;
            }
            if (empty($this->props['nav_search_placeholder'])) {
                $this->props['nav_search_placeholder'] = $child->getSearchPlaceholder() ?? 'Search';
            }
            $this->props['nav_search_debounce_ms'] = $child->getSearchDebounceMs();

            if (($rawItems = $child->getRawSearchItems()) !== null) {
                foreach ($rawItems as $rawItem) {
                    if (($item = SearchItem::from($rawItem)) !== null) {
                        $searchItemsToAdopt[] = $item;
                    }
                }
            }

            // Mode discriminator for the renderers: when an
            // `onSearchQuery` handler is wired, items flow PHP → iOS
            // per keystroke (`dynamic`); otherwise items are a static
            // corpus iOS filters locally (`static`).
            $this->props['nav_search_mode'] = isset($this->props['nav_search_on_query']) ? 'dynamic' : 'static';
            break;
        }
        foreach ($searchItemsToAdopt as $item) {
            $this->children[] = $item;
        }

        return $this->props;
    }
}
