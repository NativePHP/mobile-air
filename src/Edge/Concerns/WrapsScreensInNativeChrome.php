<?php

namespace Native\Mobile\Edge\Concerns;

use Illuminate\View\View;
use Native\Mobile\Edge\ChromeContributorRegistry;
use Native\Mobile\Edge\Elements\BottomBar;
use Native\Mobile\Edge\Elements\Fab;
use Native\Mobile\Edge\Elements\NativeRootStack;
use Native\Mobile\Edge\Elements\NativeRootTabs;
use Native\Mobile\Edge\Elements\TabAccessory;
use Native\Mobile\Edge\Elements\TopBarTitle;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
use Native\Mobile\Edge\Layouts\NativeLayout;
use SupaNative\Core\Edge\Element;
use SupaNative\Core\Edge\Elements\Column;
use SupaNative\Core\Edge\Elements\Stack;

/**
 * Everything a phone puts *around* a screen: nav bars, tab bars, a search
 * tab, a floating action button, a pinned bottom bar — and the per-screen API
 * (`navTitle()`, `searchItems()`, `shouldHideTabBar()`, …) an app overrides to
 * steer it.
 *
 * This is the single biggest reason NativeComponent could not simply move to
 * supanative/core whole. Every method below reaches for something only a
 * mobile app has: NavBar / TabBar builders, a NativeLayout, the
 * `native_root_stack` / `native_root_tabs` sentinels that iOS turns into a
 * NavigationStack and Android into a Scaffold. `wrapWithChrome()` overrides
 * core's no-op of the same name — core calls it from `view()`, `fromView()`
 * and `placeholder()` and never learns what came back.
 */
trait WrapsScreensInNativeChrome
{
    /** Layout class for this screen (set by router from route metadata). */
    protected ?string $nativeLayout = null;

    /** Imperative navbar overrides — merged onto layout's NavBar at render time. */
    protected array $nativePendingNavBarState = [];

    /** Imperative tabbar overrides — merged onto layout's TabBar at render time. */
    protected array $nativePendingTabBarState = [];

    /**
     * Hide the nav bar on this screen — shorthand for the full-bleed /
     * immersive case (photo viewer, onboarding, video). Equivalent to
     * `navigationOptions()->hidden()`. When both are set the explicit
     * builder wins. Default `false` → the layout's nav bar shows.
     */
    protected bool $hidesNavBar = false;

    /**
     * Hide the tab bar on this screen — Filament-style shorthand for the
     * common "pushed detail screen" case. Equivalent to
     * `tabBarOptions()->hidden()`. When both are set the explicit builder
     * wins. Default `false` → tab bar shows (tab-root behavior).
     */
    protected bool $hidesTabBar = false;

    /**
     * Wrap the screen's element tree with chrome from its layout and/or
     * inline chrome in its blade.
     *
     * - Looks up the layout class declared by the route or the component.
     * - Hoists inline `<native:top-bar>` / `<native:bottom-nav>` elements
     *   out of the content tree and reconstructs NavBar / TabBar builders
     *   from them (`fromElement`) — an inline bar WINS over the layout's
     *   builder bar for that slot, while the other slot still comes from
     *   the layout. Hoisted bars always drive the native-chrome sentinels
     *   (NativeRootStack / NativeRootTabs), even with no layout at all.
     * - A bar tagged with the boolean `custom` attribute is NOT hoisted:
     *   it stays in the content tree and renders as an ordinary drawn
     *   element, but still suppresses the layout's bar for that slot.
     * - Otherwise asks the layout for a NavBar / TabBar, merges in the
     *   screen's navigationOptions() and pendingNavBarState, and wraps
     *   via native chrome or the custom Column path per
     *   `NativeLayout::usesNativeChrome()`.
     */
    protected function wrapWithChrome(Element $content): Element
    {
        $layout = ($this->nativeLayout !== null && class_exists($this->nativeLayout))
            ? new ($this->nativeLayout)()
            : null;

        // ── Inline chrome (screen blade) ──
        // Non-custom bars are hoisted out of the content tree so they
        // aren't drawn inline AND translated into the same native-root
        // prop shape the layout builders produce. Custom bars stay put.
        [$inlineTopBar, $content] = $this->hoistInlineBar($content, 'top_bar');
        [$inlineBottomNav, $content] = $this->hoistInlineBar($content, 'bottom_nav');
        [$inlineSideNav, $content] = $this->hoistInlineBar($content, 'side_nav');

        // Whatever bars remain in the tree are `custom` — the dev took
        // manual control of that slot, so the layout's bar is suppressed.
        $hasCustomTopBar = $this->treeContainsType($content, 'top_bar');
        $hasCustomBottomNav = $this->treeContainsType($content, 'bottom_nav');

        // Hoist a top-level `<native:fab>` into a Stack overlay so it
        // floats above the content (its absolute insets then resolve
        // against the whole content area, not whatever container the
        // blade happened to declare it in).
        $content = $this->hoistFabOverlay($content);

        // Base case: no layout (or no bars) → the screen content is the root,
        // and it is the dev's own tree, so we must NOT append siblings to it
        // directly. `$rootOwnsChildren` tracks whether the root is a container
        // we built (and may freely append hoistable chrome to) vs. raw content.
        $root = $content;
        $rootOwnsChildren = false;

        // Inline (non-custom) chrome always renders through the native
        // sentinels; a layout additionally opts its own bars in via
        // usesNativeChrome().
        $usesNativeChrome = $inlineTopBar !== null
            || $inlineBottomNav !== null
            || ($layout?->usesNativeChrome() ?? false);

        $navBar = null;
        if ($inlineTopBar !== null) {
            $navBar = NavBar::fromElement($inlineTopBar);
            // Layout-wide chrome font still applies as a default — the
            // inline bar's own font-name attribute wins.
            $navBar->defaultFont($layout?->chromeFont());
        } elseif (! $hasCustomTopBar && $layout !== null) {
            $navBar = $layout->navBar($this);
            // Per-screen opt-out ($hidesNavBar shortcut + navigationOptions()
            // builder). On the custom-Column path hiding is identical to
            // the layout returning null. The native-chrome path instead
            // keeps the bar config and folds a `hide_nav_bar` prop onto
            // the sentinel — the NavigationStack must survive for push /
            // pop to keep working.
            if ($navBar !== null && ! $usesNativeChrome && $this->shouldHideNavBar()) {
                $navBar = null;
            }
            if ($navBar !== null) {
                $navBar->mergeOptions($this->navigationOptions());
                if (! empty($this->nativePendingNavBarState)) {
                    $navBar->mergeState($this->nativePendingNavBarState);
                }
                // Layout-wide chrome font — loses to any ->font() the
                // bar (or per-screen options/state) already set.
                $navBar->defaultFont($layout->chromeFont());
            }
        }

        $tabBar = null;
        if ($inlineBottomNav !== null) {
            $tabBar = TabBar::fromElement($inlineBottomNav);
            $tabBar->defaultFont($layout?->chromeFont());
            // Auto-highlight the tab owning the current URI — unless the
            // blade marked one `active` explicitly (highlight() respects
            // explicit choices on inline items).
            $currentUri = $this->nativeRouter?->currentUri();
            if ($currentUri !== null) {
                $tabBar->highlight($currentUri);
            }
        } elseif (! $hasCustomBottomNav && $layout !== null) {
            $tabBar = $layout->tabBar($this);
            if ($tabBar !== null) {
                $currentUri = $this->nativeRouter?->currentUri();
                if ($currentUri !== null) {
                    $tabBar->highlight($currentUri);
                }
                $tabBar->defaultFont($layout->chromeFont());
            }
        }

        if ($navBar !== null || $tabBar !== null) {
            if ($usesNativeChrome) {
                // Native chrome path: emit a `native_root_*` sentinel
                // element carrying the bar config as serialized props
                // instead of a Column of [navBar, content, tabBar]. The
                // native iOS / Android renderers for those types take over
                // and use NavigationStack / TabView / NavHost / Scaffold to
                // render chrome system-natively.
                $root = $this->wrapWithNativeChrome($content, $navBar, $tabBar, $layout);
            } else {
                $root = $this->buildChromeColumn($content, $navBar, $tabBar);
            }
            $rootOwnsChildren = true;
        }

        // A hoisted side_nav has no layout-builder counterpart; re-attach
        // it as a sentinel child of the chrome root so a drawer host
        // (plugin / NativeRootHostRegistry consumer) can pull it out —
        // mirroring how bottom_bar and chrome-contributor sentinels ride
        // on the root outside the flex flow.
        if ($inlineSideNav !== null) {
            if (! $rootOwnsChildren) {
                $wrapper = Column::make()->fill()->safeArea();
                $root->flexGrow(1);
                $wrapper->addChild($root);
                $root = $wrapper;
                $rootOwnsChildren = true;
            }
            $root->addChild($inlineSideNav);
        }

        return $this->applyChromeContributors($root, $layout, $rootOwnsChildren);
    }

    /**
     * Hoist a top-level `<native:fab>` (an `Elements\Fab` — wire type
     * `pressable`, so type-matching can't find it) out of the content's
     * flex flow and float it over the content. The fab styles itself
     * (absolute bottom-corner insets); this just guarantees the insets
     * resolve against the full content area. Scope matches the other
     * inline-chrome hoists: direct children of the root. A fab nested
     * deeper stays where it is and positions within its own container.
     *
     * When the content root is a full-size flex container (which includes
     * the collector's implicit multi-root Column — always `->fill()`),
     * the fab simply becomes the root's LAST child: it is absolutely
     * positioned, both platforms' flex renderers keep absolute children
     * out of flow measurement AND draw them last (on top), and its insets
     * resolve against the root = the full content area. Crucially the
     * measured content tree stays byte-identical to the no-fab tree.
     *
     * We must NOT wrap such content in a `Stack` overlay (the previous
     * approach): the iOS stack layout measures a non-scroll child via an
     * `.unspecified` proposal, so a `scroll_view` nested one level down
     * (stack → column → scroll_view) gets measured at its intrinsic
     * CONTENT height instead of the viewport — the scrollable range
     * collapses to ~one viewport and the list rubber-bands ("elastic"
     * scroll that never reaches the bottom). Only roots that cannot host
     * an overlay child (a `scroll_view` root would render the fab as a
     * list item, plugin roots may treat children specially) still get the
     * Stack wrapper — the shape the iOS stack layout explicitly supports
     * (it skips scroll_view children in sizeThatFits and honors their
     * fill modes in placement).
     */
    protected function hoistFabOverlay(Element $content): Element
    {
        $fab = null;
        $remaining = [];
        foreach ($content->getChildren() as $child) {
            if ($fab === null && $child instanceof Fab) {
                $fab = $child;

                continue;
            }
            $remaining[] = $child;
        }
        if ($fab === null) {
            return $content;
        }

        $layout = $content->getLayout();
        $isFullSizeFlexRoot = in_array($content->getType(), ['column', 'row', 'stack'], true)
            && ($layout['width'] ?? null) === 'fill'
            && ($layout['height'] ?? null) === 'fill';

        if ($isFullSizeFlexRoot) {
            // Re-append LAST so the fab draws above its siblings (both
            // renderers honor child order for z). Everything else —
            // including any `<native:bottom-bar>` sentinel, which stays a
            // direct child of the tree handed to `resolveBottomBar` —
            // keeps its position; the flow layout is untouched.
            $remaining[] = $fab;
            $content->setChildren($remaining);

            return $content;
        }

        // Non-flex root — float the fab over it in a Stack overlay.
        $sentinels = [];
        $kept = [];
        foreach ($remaining as $child) {
            // Keep hoistable sentinels (inline `<native:bottom-bar>`)
            // discoverable as direct children of the tree handed to
            // `resolveBottomBar` — lift them onto the Stack alongside
            // the content instead of burying them one level deeper.
            if ($child->getType() === 'bottom_bar') {
                $sentinels[] = $child;

                continue;
            }
            $kept[] = $child;
        }
        $content->setChildren($kept);

        // The content used to receive the viewport proposal as the direct
        // chrome child; inside the Stack it must opt into fill explicitly
        // or the stack places it at its intrinsic content size (breaking
        // a scroll_view root's viewport). Only fill dimensions the dev
        // left unsized — explicit sizes still win.
        if (! isset($layout['width'])) {
            $content->fillWidth();
        }
        if (! isset($layout['height'])) {
            $content->fillHeight();
        }

        $stack = Stack::make();
        $stack->fill();
        $stack->addChild($content);
        $stack->addChild($fab);
        foreach ($sentinels as $sentinel) {
            $stack->addChild($sentinel);
        }

        return $stack;
    }

    /**
     * Find (and remove) an inline chrome element of `$type` in the screen
     * tree, returning `[bar|null, content]`. Matches the scope of
     * `treeContainsType`: the root itself, or a direct child (the
     * collector's implicit Column wrapper puts top-level blade tags
     * there). Elements marked `custom` are left in place — they render
     * as ordinary drawn elements.
     *
     * @return array{0: ?Element, 1: Element}
     */
    protected function hoistInlineBar(Element $content, string $type): array
    {
        // Root IS the bar (a blade whose only top-level tag is the bar).
        if ($content->getType() === $type && ! $content->isCustomChrome()) {
            return [$content, Column::make()->fill()];
        }

        $found = null;
        $remaining = [];
        foreach ($content->getChildren() as $child) {
            if ($found === null && $child->getType() === $type && ! $child->isCustomChrome()) {
                $found = $child;

                continue;
            }
            $remaining[] = $child;
        }
        if ($found !== null) {
            $content->setChildren($remaining);
        }

        return [$found, $content];
    }

    /**
     * Build the custom `Column` wrapper for the non-native chrome path:
     * [navBar?, content, tabBar?] with the right safe-area edges freed.
     */
    private function buildChromeColumn(
        Element $content,
        ?NavBar $navBar,
        ?TabBar $tabBar,
    ): Element {
        // Pick the right safe-area variant based on which bars own which
        // edges. When a TabBar exists at the bottom, it handles its own
        // home-indicator inset internally so its bg can reach the screen
        // edge — the wrapper frees the bottom edge by using `safeAreaTop()`.
        // Same logic mirrored for the top edge when a NavBar exists.
        // When both bars exist, the wrapper applies neither edge — both
        // bars handle their own.
        $wrapper = Column::make()->fill();
        if ($navBar !== null && $tabBar === null) {
            $wrapper->safeAreaBottom();   // navBar owns top, wrapper owns bottom
        } elseif ($tabBar !== null && $navBar === null) {
            $wrapper->safeAreaTop();      // tabBar owns bottom, wrapper owns top
        }
        // Both bars present: neither edge applied at the wrapper level.

        if ($navBar !== null) {
            $wrapper->addChild($navBar->toElement());
        }

        // Force the content slot to flex-grow so it gets a bounded height
        // (= screen − chrome) inside the wrapper column. Without this, a
        // SwiftUI ScrollView at the blade root reports its intrinsic content
        // height, FlexContainer gives it that much, and scrolling never
        // engages because viewport == content.
        //
        // NOTE: do NOT also apply ->fillWidth() here. fillWidth maps to
        // .frame(maxWidth: .infinity), which eats SwiftUI's height proposal
        // on the way through to the inner ScrollView and re-creates the
        // "no scroll" symptom. flex-grow alone is enough — FlexContainer's
        // place(at:proposal:) gives the content the right height directly.
        $content->flexGrow(1);
        $wrapper->addChild($content);

        if ($tabBar !== null) {
            $wrapper->addChild($tabBar->toElement());
        }

        return $wrapper;
    }

    /**
     * Run plugin-registered chrome contributors (the PHP half of the chrome
     * seam) and append any hoistable sentinel elements they produce to the
     * published root. Core stays chrome-agnostic — it never knows what the
     * sentinels are; a native root host pulls them out and renders the chrome.
     *
     * When the root is the dev's own content (no chrome wrapper built), append
     * would mutate their tree, so we wrap content + sentinels in a minimal
     * safe-area Column instead. This only happens when a contributor actually
     * produces something, so chrome-less, contributor-less screens are
     * returned untouched — preserving existing behavior exactly.
     */
    private function applyChromeContributors(Element $root, ?NativeLayout $layout, bool $rootOwnsChildren): Element
    {
        $renderPartial = fn (View $view): Element => $this->fromViewPartial($view);

        $extras = ChromeContributorRegistry::collect($this, $layout, $renderPartial);
        if (empty($extras)) {
            return $root;
        }

        if (! $rootOwnsChildren) {
            // Root is the dev's raw content — wrap it so the hoistable
            // sentinels ride alongside without altering the content's layout.
            $wrapper = Column::make()->fill()->safeArea();
            $root->flexGrow(1);
            $wrapper->addChild($root);
            $root = $wrapper;
        }

        foreach ($extras as $extra) {
            $root->addChild($extra);
        }

        return $root;
    }

    /**
     * Native-chrome path. Emits a `NativeRootStack` or `NativeRootTabs`
     * sentinel element instead of a custom Column-of-bars layout. The
     * iOS / Android renderers for those types route to NavigationStack /
     * TabView / NavHost / Scaffold.
     *
     * Layout, in either case:
     *   - Bar config serialized as flat element props
     *   - Tabs (when present) emitted as `bottom_nav_item` children
     *   - NavBar actions (when present) emitted as `top_bar_action` children
     *   - Screen content appended as the final child
     */
    protected function wrapWithNativeChrome(
        Element $content,
        ?NavBar $navBar,
        ?TabBar $tabBar,
        ?NativeLayout $layout,
    ): Element {
        if ($tabBar !== null) {
            $root = NativeRootTabs::make();
            $attrs = $tabBar->toRootProps();

            // Fold NavBar config in via nav-prefixed keys when both exist
            // (each tab hosts its own NavigationStack natively).
            if ($navBar !== null) {
                foreach ($navBar->toRootProps() as $key => $value) {
                    $attrs['nav'.ucfirst($key)] = $value;
                }
            }
            // Active tab's screen URI — used by the iOS bridge's per-URI
            // diff to keep tab-switch animations smooth.
            $attrs['currentUri'] = $this->nativeRouter?->currentUri() ?? '';

            // Per-screen tab-bar overrides ($hidesTabBar shortcut +
            // tabBarOptions() builder). Folded onto the chrome sentinel
            // as flat props so the iOS / Android renderers don't need to
            // re-derive visibility from URI matching or `nav_back`.
            if ($this->shouldHideTabBar()) {
                $attrs['hideTabBar'] = true;
            }
            // Per-screen nav-bar opt-out, same shape as `hideTabBar` — the
            // renderers hide the toolbar for this destination only.
            if ($navBar !== null && $this->shouldHideNavBar()) {
                $attrs['hideNavBar'] = true;
            }
            $tabOptions = $this->tabBarOptions();
            if ($tabOptions !== null) {
                if ($tabOptions->highlight !== null) {
                    $attrs['tabHighlight'] = $tabOptions->highlight;
                }
                if ($tabOptions->activeColor !== null) {
                    $attrs['activeColor'] = $tabOptions->activeColor;
                }
                if ($tabOptions->backgroundColor !== null) {
                    $attrs['backgroundColor'] = $tabOptions->backgroundColor;
                }
                if ($tabOptions->font !== null) {
                    $attrs['fontName'] = $tabOptions->font;
                }
            }

            // Two sources contribute to the search corpus on the
            // active screen, in priority order:
            //
            //   1. `$pendingSearchResults` — latest return from
            //      `onSearchQuery($q)` if the screen overrides it.
            //      Written by `dispatch()` when a `search_query`-kinded
            //      callback fires.
            //   2. `searchItems()` — static corpus.
            //
            // We intentionally DON'T pre-call `onSearchQuery('')` here
            // — that runs synchronously on the runloop's render thread
            // and would block navigation if the screen hits the network
            // for its seed data. Dynamic-mode screens start with an
            // empty list; the renderer shows a "Type to search" empty
            // state until the first keystroke fires TEXT_CHANGE.
            $hasDynamicQuery = $this->hasOnSearchQueryOverride();
            $screenSearchItems = $this->pendingSearchResults
                ?? $this->searchItems();

            // Devs can return Laravel `View` instances in their search
            // items (so they can write `view('native.row', [...])` and
            // get IDE CMD-click navigation to the Blade file). Convert
            // them to `Element` instances here before they reach
            // `SearchItem::from` — that's a static normalizer with no
            // component reference, so the conversion can't happen
            // further down.
            if (is_array($screenSearchItems)) {
                $screenSearchItems = array_map(
                    fn ($item) => $item instanceof View
                        ? $this->fromViewPartial($item)
                        : $item,
                    $screenSearchItems
                );
            }

            if ($hasDynamicQuery) {
                $attrs['navSearchOnQueryMethod'] = 'onSearchQuery';
            }

            $root->applyAttributes($attrs);

            // Tab items as bottom_nav_item children — builder tabs and
            // prebuilt inline items uniformly via tabElements(). For the
            // search-role tab we inject the resolved corpus above; when
            // it's null (screen opted out — neither `searchItems()` nor
            // `onSearchQuery()` overridden), the iOS / Android
            // renderer hides the search tab via the sticky-inclusion
            // pattern (visible only when currently selected, so the
            // TabView reconciliation stays clean).
            foreach ($tabBar->tabElements() as $item) {
                if ($item->isSearchTab() && $screenSearchItems !== null) {
                    $item->setRawSearchItems($screenSearchItems);
                }
                $root->addChild($item);
            }
            // NavBar actions (if any) as top_bar_action children.
            if ($navBar !== null) {
                foreach ($navBar->actionElements() as $action) {
                    $root->addChild($action);
                }
                // Optional custom principal-slot content (logo / titleView)
                // wrapped in a `TopBarTitle` marker so the renderer renders it
                // in place of the string title.
                if (($titleEl = $this->topBarTitleElement($navBar)) !== null) {
                    $root->addChild($titleEl);
                }
            }
            // Optional persistent accessory pinned above the tab bar
            // (Apple's MiniPlayer pattern). Wrapped in a `TabAccessory`
            // marker element so the renderer can pick it out of children
            // alongside tabs and screen content.
            $accessory = $layout?->tabBarAccessory($this);
            if ($accessory !== null) {
                $wrapper = TabAccessory::make();
                $wrapper->addChild($accessory);
                $root->addChild($wrapper);
            }
            // Optional bottom-pinned content (chat input, search bar,
            // contextual menu) — from an inline `<native:bottom-bar>` in the
            // screen blade or the layout's `bottomBar()`. Pinned via
            // `.safeAreaInset(.bottom)`, which keeps it above the keyboard.
            // Resolved (and hoisted out of `$content`) BEFORE appending the
            // screen content so an inline bar isn't rendered twice.
            $bottomBar = $this->resolveBottomBar($content, $layout);
            if ($bottomBar !== null) {
                $root->addChild($bottomBar);
            }
            // Active screen content as the final child.
            $root->addChild($content);

            return $root;
        }

        if ($navBar !== null) {
            $root = NativeRootStack::make();
            $attrs = $navBar->toRootProps();
            // The per-URI tree cache on iOS keys off this so the
            // NavigationCoordinator can route push / pop / no-op
            // correctly across publishes.
            $attrs['currentUri'] = $this->nativeRouter?->currentUri() ?? '';
            // Per-screen nav-bar opt-out — the sentinel (and its
            // NavigationStack) survives; only the toolbar hides.
            if ($this->shouldHideNavBar()) {
                $attrs['hideNavBar'] = true;
            }
            $root->applyAttributes($attrs);
            foreach ($navBar->actionElements() as $action) {
                $root->addChild($action);
            }
            // Optional custom principal-slot content (logo / titleView).
            if (($titleEl = $this->topBarTitleElement($navBar)) !== null) {
                $root->addChild($titleEl);
            }
            // Optional bottom-pinned content — same shape as the tabs
            // path above so a stack-only layout (no tab bar) can still
            // pin a chat input / search bar above the keyboard. Prefers an
            // inline `<native:bottom-bar>` from the screen blade, else the
            // layout's `bottomBar()`; hoisted out of `$content` here.
            $bottomBar = $this->resolveBottomBar($content, $layout);
            if ($bottomBar !== null) {
                $root->addChild($bottomBar);
            }
            $root->addChild($content);

            return $root;
        }

        return $content;
    }

    /**
     * Wrap a NavBar's `titleView()` / `logo()` content in a `TopBarTitle`
     * marker for the native-chrome renderers to render in the bar's principal
     * slot, or null when the bar uses a plain string title. A Blade view is
     * rendered against this screen (so `@tap` / bindings resolve) first.
     */
    protected function topBarTitleElement(NavBar $navBar): ?Element
    {
        $titleView = $navBar->getTitleView();
        if ($titleView === null) {
            return null;
        }

        // An inline `<native:top-bar-title>` arrives already wrapped (the
        // collector built the marker itself) — re-wrapping would nest a
        // `top_bar_title` inside a `top_bar_title` and the renderers, which
        // draw the marker's direct children, would paint nothing.
        if ($titleView instanceof TopBarTitle) {
            return $titleView;
        }

        $wrapper = TopBarTitle::make();
        $wrapper->addChild($titleView instanceof View ? $this->fromViewPartial($titleView) : $titleView);

        return $wrapper;
    }

    /**
     * Walk the root element (and one level of children if it's an implicit
     * Column wrapper) looking for an element of $type.
     *
     * NativeElementCollector::collect() wraps multi-root trees in an
     * implicit Column, so checking only $tree's direct children doesn't
     * catch top-level <native:top-bar> when the blade also has siblings.
     */
    protected function treeContainsType(Element $tree, string $type): bool
    {
        if ($tree->getType() === $type) {
            return true;
        }
        foreach ($tree->getChildren() as $child) {
            if ($child->getType() === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find and REMOVE the first direct child of `$tree` matching `$type`,
     * returning it (or null). Shallow by design — matches
     * `treeContainsType`'s scope, and inline chrome sentinels like
     * `<native:bottom-bar>` are authored as top-level children of the screen
     * root. Used to hoist that sentinel out of the screen tree so it can be
     * re-attached to the native-chrome root without also rendering inline.
     */
    protected function extractDirectChildOfType(Element $tree, string $type): ?Element
    {
        $found = null;
        $remaining = [];
        foreach ($tree->getChildren() as $child) {
            if ($found === null && $child->getType() === $type) {
                $found = $child;

                continue;
            }
            $remaining[] = $child;
        }
        if ($found !== null) {
            $tree->setChildren($remaining);
        }

        return $found;
    }

    /**
     * Resolve the `bottom_bar` sentinel to attach to a native-chrome root.
     *
     * An inline `<native:bottom-bar>` in the screen blade wins — the dev
     * placed it explicitly. It's already a `bottom_bar` marker (its content
     * is its children), so it's hoisted out of `$content` and used as-is.
     * Otherwise fall back to the layout's `bottomBar()` builder, wrapping its
     * result in the same marker the renderers expect.
     *
     * Either way the renderers pin it via `.safeAreaInset(.bottom)` (iOS) /
     * `Scaffold(bottomBar=)` + `imePadding()` (Android), which keeps it above
     * the software keyboard using each platform's native mechanism.
     */
    protected function resolveBottomBar(Element $content, ?NativeLayout $layout): ?Element
    {
        $inline = $this->extractDirectChildOfType($content, 'bottom_bar');
        if ($inline !== null) {
            return $inline;
        }

        $layoutBar = $layout?->bottomBar($this);
        if ($layoutBar !== null) {
            $wrapper = BottomBar::make();
            $wrapper->addChild($layoutBar);

            return $wrapper;
        }

        return null;
    }

    /**
     * Override to provide a default screen title that the layout's
     * NavBar can read.
     */
    public function navTitle(): string
    {
        return '';
    }

    /**
     * Override to provide a static search corpus for this screen.
     * Returned items can be strings, structured arrays (`title`,
     * `subtitle`, `leading`, `trailing`, `url` or `method`), or
     * `Element` instances for fully custom rows. iOS filters locally
     * against the user's query for snappy per-keystroke response.
     *
     * Returning `null` (the default) causes the search tab to be
     * omitted entirely while this screen is active. That's how a layout
     * shared across multiple screens can scope search to only the
     * screens where it makes sense — e.g. Home defines a list of
     * articles, Profile returns null and gets no search tab.
     *
     * @return list<mixed>|null
     */
    public function searchItems(): ?array
    {
        return null;
    }

    /**
     * Override for dynamic-mode search — fires on each (debounced)
     * keystroke. The return value replaces the search items for the
     * next frame. Ideal for Eloquent queries, external APIs, or
     * anything that can't be pre-computed.
     *
     *     public function onSearchQuery(string $query): array
     *     {
     *         return User::where('name', 'like', "%{$query}%")
     *             ->limit(20)
     *             ->get()
     *             ->map(fn ($u) => [
     *                 'title' => $u->name,
     *                 'subtitle' => $u->email,
     *                 'url' => "/people/{$u->id}",
     *             ])
     *             ->toArray();
     *     }
     *
     * When overridden, the framework treats this as the source of
     * truth and disables iOS-side client filtering. `searchItems()`
     * (if also overridden) still seeds the initial item list shown
     * before the user types.
     *
     * The default returns an empty array; the framework detects
     * "overridden" via reflection (declaring-class != NativeComponent).
     *
     * @return list<mixed>
     */
    public function onSearchQuery(string $query): array
    {
        return [];
    }

    /**
     * True when this component overrides `onSearchQuery()`. Used by
     * `wrapWithNativeChrome` to decide whether to enable dynamic mode
     * and register the search-query callback.
     *
     * `self::class` is the class that USES this trait, and the default
     * `onSearchQuery()` above reports that same class as its declaring
     * class — so the comparison still means "a screen redeclared this",
     * exactly as it did when both lived in NativeComponent's body.
     */
    final protected function hasOnSearchQueryOverride(): bool
    {
        $reflection = new \ReflectionMethod($this, 'onSearchQuery');

        return $reflection->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Override to provide structured per-screen NavBar overrides that
     * merge onto the layout's NavBar.
     */
    public function navigationOptions(): ?NavBarOptions
    {
        return null;
    }

    /**
     * Resolved "should the nav bar be hidden on this screen?" — combines
     * the boolean shortcut and the builder. The builder wins on conflict
     * (more explicit). On the custom-Column chrome path [wrapWithChrome]
     * simply skips the bar; on the native-chrome path
     * [wrapWithNativeChrome] folds a `hide_nav_bar` prop onto the chrome
     * sentinel (the sentinel itself must survive — iOS keys push / pop
     * off it).
     */
    public function shouldHideNavBar(): bool
    {
        $options = $this->navigationOptions();
        if ($options !== null && $options->hidden !== null) {
            return $options->isHidden();
        }

        return $this->hidesNavBar;
    }

    /**
     * Override to provide structured per-screen tab-bar overrides that
     * merge onto the layout's TabBar — visibility, active highlight,
     * colors. Per-screen tab content edits (insert/remove tabs) are
     * intentionally out of scope; define tabs once at the layout level.
     *
     *   public function tabBarOptions(): ?TabBarOptions
     *   {
     *       return TabBarOptions::make()
     *           ->hidden()
     *           ->highlight('chats');
     *   }
     */
    public function tabBarOptions(): ?TabBarOptions
    {
        return null;
    }

    /**
     * Resolved "should the tab bar be hidden on this screen?" — combines
     * the boolean shortcut and the builder. The builder wins on conflict
     * (more explicit). Used by [wrapWithNativeChrome] to fold the
     * `hide_tab_bar` prop onto the chrome sentinel.
     */
    public function shouldHideTabBar(): bool
    {
        $options = $this->tabBarOptions();
        if ($options !== null && $options->hidden !== null) {
            return $options->isHidden();
        }

        return $this->hidesTabBar;
    }

    /**
     * Imperative override: mutate the navbar at any time during the
     * runloop. The next render reads the merged result.
     */
    public function setNavBar(array $options): void
    {
        $this->nativePendingNavBarState = array_merge($this->nativePendingNavBarState, $options);
    }

    /**
     * Imperative override: mutate the tabbar.
     */
    public function setTabBar(array $options): void
    {
        $this->nativePendingTabBarState = array_merge($this->nativePendingTabBarState, $options);
    }

    /**
     * Set by the router from the resolved route's metadata so the
     * component knows which layout class wraps it.
     */
    public function setLayout(?string $layoutClass): void
    {
        $this->nativeLayout = $layoutClass;
    }
}
