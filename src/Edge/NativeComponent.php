<?php

namespace Native\Mobile\Edge;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Elements\ActivityIndicator;
use Native\Mobile\Edge\Elements\BottomBar;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\NativeRootStack;
use Native\Mobile\Edge\Elements\NativeRootTabs;
use Native\Mobile\Edge\Elements\TabAccessory;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\JumpBridge;
use Native\Mobile\Support\NativeCallbacks;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

abstract class NativeComponent
{
    const EVENT_HOT_RELOAD = 15;

    const EVENT_NATIVE = 20;

    private static bool $dumpHandlerRegistered = false;

    private ?NativeDumpException $dumpException = null;

    private ?\Throwable $errorException = null;

    private int $overlayFontSize = 12;

    private array $overlayCallbackIds = [];

    /** @var array<string, string> event name → method name */
    private array $nativeEventListeners = [];

    /** #[Computed] map: property name → ['method' => string, 'persist' => bool]. Null until reflected. */
    private ?array $computedMeta = null;

    /** Memoized #[Computed] results for the current frame (persist entries survive across frames). */
    private array $computedCache = [];

    /** #[Poll] definitions: list of ['method' => ?string, 'ms' => int, 'next' => float(ms)]. Null until reflected. */
    private ?array $pollDefinitions = null;

    /** Blade `native:poll` re-render timers: interval(ms) → next deadline(ms). Rebuilt from the template each frame. */
    private array $bladePollDeadlines = [];

    /** Whether this component class carries #[Lazy]. Null until reflected. */
    private ?bool $lazy = null;

    /** Guards publishPlaceholder() so a lazy screen paints its placeholder at most once. */
    private bool $placeholderPublished = false;

    // Internal component state — prefixed `native*` so a user component's own
    // public props (e.g. $running, $params, $layout) can never shadow and crash
    // the render loop. Do not name a public prop with one of these.
    protected CallbackRegistry $nativeCallbacks;

    protected bool $nativeHasError = false;

    protected bool $nativeRunning = true;

    protected ?NavigationIntent $nativeNavigationIntent = null;

    protected ?NativeRouter $nativeRouter = null;

    protected array $nativeParams = [];

    protected array $nativeNavigationData = [];

    /** Layout class for this screen (set by router from route metadata). */
    protected ?string $nativeLayout = null;

    /** Imperative navbar overrides — merged onto layout's NavBar at render time. */
    protected array $nativePendingNavBarState = [];

    /** Imperative tabbar overrides — merged onto layout's TabBar at render time. */
    protected array $nativePendingTabBarState = [];

    /**
     * Phase 2 — per-id content hashes from the previously-published frame.
     * Element::toArray() consults this to emit compact REUSE markers for
     * subtrees whose hash hasn't changed, instead of re-serializing them.
     *
     * Only maintained across frames when NPHP_FLAG_SUBTREE_MEMO is set in
     * the runtime flags (see Phase 0). When the bit is clear we pass an
     * empty array (and discard the populated one toArray hands back), so
     * every node is emitted FULL — identical wire bytes as pre-Phase-2.
     *
     * Also force-cleared every NPHP_FORCE_FULL_FRAME_EVERY frames as a
     * self-healing heartbeat against hash collisions / missed updates
     * (§3 Phase 2 pitfalls). Cleared on screen change via the natural
     * component-instance lifetime.
     */
    private array $lastNodeHashes = [];

    /** Publish counter for the forceFullFrame heartbeat. */
    private int $publishCount = 0;

    /** Last-seen value of the region's `force_full_frame_epoch` atomic.
     *  When the bridge or framework bumps that atomic (on
     *  nativephp_element_reset(), screen-stop, hot reload, navigation
     *  away/back), the next memoizedToArray() call notices the epoch
     *  shifted and discards $lastNodeHashes — so we never emit REUSE
     *  markers that reference ids the native reader's previousTree
     *  no longer has, which would otherwise truncate the rendered tree
     *  (and silently strip on_press from spliced children). */
    private int $lastSeenForceFullFrameEpoch = 0;

    /** Heartbeat period — every Nth publish, drop the hash store so the
     *  next frame re-validates the whole tree. ~2s at 60fps publish rate. */
    private const NPHP_FORCE_FULL_FRAME_EVERY = 120;

    public function render(): Element|View
    {
        return $this->view(static::inferViewName());
    }

    /**
     * Invoke the subclass's `render()` and normalize its return value
     * to an `Element`. Subclasses may return either an `Element`
     * (built via `$this->view(...)` / element builders) or a Laravel
     * `View` instance (built via the global `view('native.foo')`
     * helper — preferred for IDE CMD-click navigation). Centralizing
     * the dispatch here keeps the three call sites in the runloop
     * from each having to repeat the type check.
     */
    private function renderToElement(): Element
    {
        $result = $this->render();

        return $result instanceof View
            ? $this->fromView($result)
            : $result;
    }

    /**
     * Phase 2 — serialize the rendered Element to its wire array, with
     * subtree-memo (REUSE markers) enabled when the runtime flag is on.
     *
     * When NPHP_FLAG_SUBTREE_MEMO is set:
     *   - `$lastNodeHashes` is threaded through `toArray()` and updated
     *     after each FULL emit. Next frame compares against it and emits
     *     REUSE markers for unchanged subtrees.
     *   - Every NPHP_FORCE_FULL_FRAME_EVERY frames the hash store is
     *     dropped on entry, forcing a clean re-validation (defense-in-
     *     depth against hash collisions / missed updates).
     *
     * When the flag is clear: pass a throwaway hashes array — toArray()
     * never finds a matching prior, so every node is emitted FULL.
     * Wire bytes are identical to pre-Phase-2.
     */
    private function memoizedToArray(Element $element): array
    {
        $this->publishCount++;

        $memoEnabled = (nativephp_runtime_flags() & 0x02) !== 0;
        $nextId = 1;
        $emitted = [];

        if (! $memoEnabled) {
            // Throwaway array — never read across frames.
            $throwaway = [];

            return $element->toArray($this->nativeCallbacks, $nextId, '', 0, $emitted, $throwaway);
        }

        // Explicit invalidation: when the C extension bumps the region's
        // force_full_frame_epoch (nativephp_element_reset, screen
        // teardown, hot reload — anything that swaps the native
        // previousTree out from under us), drop the hash store so the
        // next publish emits FULL across the whole tree. Without this,
        // PHP-side REUSE markers would reference ids that the native
        // reader's index can no longer resolve, silently truncating the
        // rendered tree and stripping on_press handlers from spliced
        // children (= why nav links appear broken after going to a sub-
        // screen and back).
        $epoch = nativephp_force_full_frame_epoch();
        if ($epoch !== $this->lastSeenForceFullFrameEpoch) {
            $this->lastNodeHashes = [];
            $this->lastSeenForceFullFrameEpoch = $epoch;
        }

        // forceFullFrame heartbeat. Resets one frame in 120; the cost is
        // amortized across frames and self-heals any stale entries.
        if ($this->publishCount % self::NPHP_FORCE_FULL_FRAME_EVERY === 1) {
            $this->lastNodeHashes = [];
        }

        return $element->toArray(
            $this->nativeCallbacks,
            $nextId,
            '',
            0,
            $emitted,
            $this->lastNodeHashes,
        );
    }

    /**
     * Infer the Blade view name from the class name, Livewire-style.
     * e.g. Explore → explore, UserProfile → user-profile
     */
    public static function inferViewName(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * Override to render directly into the C flat buffer via streaming.
     * Call streamView() here instead of view().
     * Return true to skip legacy toArray/publish path.
     */
    protected function renderStreaming(): bool
    {
        return false;
    }

    protected function view(string $name, array $data = []): Element
    {
        $viewData = array_merge($this->getPublicProperties(), $data);

        NativeElementCollector::reset();
        NativeElementCollector::setCallbacks($this->nativeCallbacks);

        $this->renderBladeBoundToSelf("native.{$name}", $viewData);

        $this->syncBladePolls(NativeElementCollector::takePollIntervals());

        $content = NativeElementCollector::collect();

        return $this->wrapWithChrome($content);
    }

    /**
     * Render a Blade partial into an `Element` tree without wrapping
     * it in layout chrome. Useful for any place that builds Elements
     * outside a `render()` call — most notably custom search-result
     * rows from `onSearchQuery()` / `searchItems()`:
     *
     *     return collect($posts)
     *         ->map(fn ($p) => $this->partial('search-row', ['post' => $p]))
     *         ->toArray();
     *
     * Counterpart of `view()` minus the `wrapWithChrome()` call. Safe
     * to call multiple times in a loop — each call resets the static
     * collector and returns a fresh detached subtree.
     */
    protected function partial(string $name, array $data = []): Element
    {
        $viewData = array_merge($this->getPublicProperties(), $data);

        NativeElementCollector::reset();
        NativeElementCollector::setCallbacks($this->nativeCallbacks);

        $this->renderBladeBoundToSelf("native.{$name}", $viewData);

        return NativeElementCollector::collect();
    }

    /**
     * Convert a Laravel `View` instance into a screen-level `Element`
     * tree. Equivalent to `view($name, $data)` but accepting an
     * already-constructed `View` — so devs can write
     *
     *     public function render(): View
     *     {
     *         return view('native.home');
     *     }
     *
     * and let the IDE CMD-click the view name to the Blade file
     * (PhpStorm / Laravel Idea / Intelephense all recognize the global
     * `view()` helper). The runloop calls this when `render()` returns
     * a `View`; devs don't usually invoke it directly.
     */
    protected function fromView(View $view): Element
    {
        $viewData = array_merge($this->getPublicProperties(), $view->getData());

        NativeElementCollector::reset();
        NativeElementCollector::setCallbacks($this->nativeCallbacks);

        $this->renderBladeBoundToSelf($view->getName(), $viewData);

        $this->syncBladePolls(NativeElementCollector::takePollIntervals());

        $content = NativeElementCollector::collect();

        return $this->wrapWithChrome($content);
    }

    /**
     * Convert a Laravel `View` to an `Element` tree without wrapping
     * in layout chrome — partial equivalent of `fromView`. Lets devs
     * use `view('native.search-row', [...])` anywhere `partial(...)`
     * would have been used, with IDE-navigable view names:
     *
     *     return collect($posts)
     *         ->map(fn ($p) => view('native.search-row', ['post' => $p]))
     *         ->toArray();
     *
     * `wrapWithNativeChrome` calls this automatically when it spots a
     * `View` instance in a screen's search-items list.
     */
    protected function fromViewPartial(View $view): Element
    {
        $viewData = array_merge($this->getPublicProperties(), $view->getData());

        NativeElementCollector::reset();
        NativeElementCollector::setCallbacks($this->nativeCallbacks);

        $this->renderBladeBoundToSelf($view->getName(), $viewData);

        return NativeElementCollector::collect();
    }

    /**
     * Wrap the screen's element tree with chrome from its layout.
     *
     * - Looks up the layout class declared by the route or the component.
     * - Asks the layout for a NavBar / TabBar.
     * - Merges in the screen's navigationOptions() and pendingNavBarState.
     * - Skips chrome the screen overrode inline in its blade
     *   (i.e., a top-level <native:top-bar> or <native:bottom-nav>).
     * - Wraps everything in a Column.fill().safeArea() with the chrome
     *   stacked above and below the content.
     */
    protected function wrapWithChrome(Element $content): Element
    {
        $layout = ($this->nativeLayout !== null && class_exists($this->nativeLayout))
            ? new ($this->nativeLayout)()
            : null;

        // Base case: no layout (or no bars) → the screen content is the root,
        // and it is the dev's own tree, so we must NOT append siblings to it
        // directly. `$rootOwnsChildren` tracks whether the root is a container
        // we built (and may freely append hoistable chrome to) vs. raw content.
        $root = $content;
        $rootOwnsChildren = false;

        if ($layout !== null) {
            // If the screen blade already contains TopBar / BottomNav at the
            // root level, the dev took manual control — skip layout chrome
            // for those slots.
            $hasInlineNavBar = $this->treeContainsType($content, 'top_bar');
            $hasInlineTabBar = $this->treeContainsType($content, 'bottom_nav');

            $navBar = null;
            if (! $hasInlineNavBar) {
                $navBar = $layout->navBar($this);
                if ($navBar !== null) {
                    $navBar->mergeOptions($this->navigationOptions());
                    if (! empty($this->nativePendingNavBarState)) {
                        $navBar->mergeState($this->nativePendingNavBarState);
                    }
                }
            }

            $tabBar = null;
            if (! $hasInlineTabBar) {
                $tabBar = $layout->tabBar($this);
                if ($tabBar !== null) {
                    $currentUri = $this->nativeRouter?->currentUri();
                    if ($currentUri !== null) {
                        $tabBar->highlight($currentUri);
                    }
                }
            }

            if ($navBar !== null || $tabBar !== null) {
                if ($layout->usesNativeChrome()) {
                    // Native chrome path: when a layout opts in via
                    // `usesNativeChrome()`, emit a `native_root_*` sentinel
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
        }

        return $this->applyChromeContributors($root, $layout, $rootOwnsChildren);
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
        NativeLayout $layout,
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

            // Tab items as bottom_nav_item children. For the search-
            // role tab we inject the resolved corpus above; when it's
            // null (screen opted out — neither `searchItems()` nor
            // `onSearchQuery()` overridden), the iOS / Android
            // renderer hides the search tab via the sticky-inclusion
            // pattern (visible only when currently selected, so the
            // TabView reconciliation stays clean).
            foreach ($tabBar->getTabs() as $tab) {
                if ($tab->isSearchTab() && $screenSearchItems !== null) {
                    $tab->setSearchItems($screenSearchItems);
                }
                $root->addChild($tab->toElement());
            }
            // NavBar actions (if any) as top_bar_action children.
            if ($navBar !== null) {
                foreach ($navBar->getActions() as $action) {
                    $root->addChild($action->toElement());
                }
            }
            // Optional persistent accessory pinned above the tab bar
            // (Apple's MiniPlayer pattern). Wrapped in a `TabAccessory`
            // marker element so the renderer can pick it out of children
            // alongside tabs and screen content.
            $accessory = $layout->tabBarAccessory($this);
            if ($accessory !== null) {
                $wrapper = TabAccessory::make();
                $wrapper->addChild($accessory);
                $root->addChild($wrapper);
            }
            // Optional bottom-pinned content (chat input, search bar,
            // contextual menu). Wrapped in a `BottomBar` marker so the
            // renderer can pick it out and pin via `.safeAreaInset(.bottom)`.
            $bottomBar = $layout->bottomBar($this);
            if ($bottomBar !== null) {
                $bottomWrapper = BottomBar::make();
                $bottomWrapper->addChild($bottomBar);
                $root->addChild($bottomWrapper);
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
            $root->applyAttributes($attrs);
            foreach ($navBar->getActions() as $action) {
                $root->addChild($action->toElement());
            }
            // Optional bottom-pinned content — same shape as the tabs
            // path above so a stack-only layout (no tab bar) can still
            // pin a chat input / search bar above the keyboard.
            $bottomBar = $layout->bottomBar($this);
            if ($bottomBar !== null) {
                $bottomWrapper = BottomBar::make();
                $bottomWrapper->addChild($bottomBar);
                $root->addChild($bottomWrapper);
            }
            $root->addChild($content);

            return $root;
        }

        return $content;
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
     */
    final protected function hasOnSearchQueryOverride(): bool
    {
        $reflection = new \ReflectionMethod($this, 'onSearchQuery');

        return $reflection->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Latest results returned by `onSearchQuery($q)`. Written by
     * `dispatch()` when a `search_query`-kinded callback fires;
     * consumed by `wrapWithNativeChrome` (preferred over
     * `searchItems()`'s static return).
     *
     * @var list<mixed>|null
     */
    protected ?array $pendingSearchResults = null;

    /**
     * Override to provide structured per-screen NavBar overrides that
     * merge onto the layout's NavBar.
     */
    public function navigationOptions(): ?NavBarOptions
    {
        return null;
    }

    /**
     * Hide the tab bar on this screen — Filament-style shorthand for the
     * common "pushed detail screen" case. Equivalent to
     * `tabBarOptions()->hidden()`. When both are set the explicit builder
     * wins. Default `false` → tab bar shows (tab-root behavior).
     */
    protected bool $hidesTabBar = false;

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

    /**
     * Render a Blade view with `$this` bound to the component instance, so
     * templates can call methods and read properties on the component
     * directly — same convenience Livewire components offer.
     *
     * Mirrors what Livewire's `ExtendedCompilerEngine::evaluatePath()` does
     * for its components, but applies it to NativeComponent renders. Without
     * this, `$this` evaluates to the view engine (or nothing in some paths)
     * and bare `$this->property` from the blade fails with "Using $this when
     * not in object context."
     */
    private function renderBladeBoundToSelf(string $name, array $data): void
    {
        // `view()` with no args returns the Factory itself; `view($name, $data)`
        // returns a View. We need the View to access its engine + path.
        $view = view($name, $data);
        $engine = $view->getEngine();

        if (! $engine instanceof CompilerEngine) {
            // Non-blade engine — fall back to the standard render path.
            // $this won't be bound, but at least the view still runs.
            $view->render();

            return;
        }

        $compiler = $engine->getCompiler();
        $bladePath = $view->getPath();

        // Enable native-tag precompilation only for the duration of this native
        // render. Both the initial compile and any nested compiles triggered
        // during execution (native partials, `<native:virtual-list>` items)
        // must run under the flag; everything outside stays plain HTML. Restore
        // the previous state so nested native renders unwind cleanly.
        $wasActive = NativeTagPrecompiler::setActive(true);

        try {
            if ($compiler->isExpired($bladePath)) {
                $compiler->compile($bladePath);
            }
            $compiledPath = $compiler->getCompiledPath($bladePath);

            // Use the View's full data set — Factory injects `$__env` and other
            // helpers compiled views depend on (`@include`, `@yield`, the loop
            // stack, etc.). Skipping that produces "Undefined variable $__env".
            $viewData = $view->gatherData();

            // Closure::bind ties `$this` inside the include to the component
            // instance and grants access to protected/private members via the
            // class-scope second argument.
            \Closure::bind(function () use ($compiledPath, $viewData) {
                extract($viewData, EXTR_SKIP);
                include $compiledPath;
            }, $this, static::class)();
        } finally {
            NativeTagPrecompiler::setActive($wasActive);
        }
    }

    /**
     * Streaming view — renders Blade directly into C flat buffer.
     * No Element objects, no toArray(), no intermediate PHP arrays.
     */
    protected function streamView(string $name, array $data = []): void
    {
        $viewData = array_merge($this->getPublicProperties(), $data);

        NativeElementCollector::setCallbacks($this->nativeCallbacks);
        NativeElementCollector::setStreaming(true);

        nphp_frame_begin();

        try {
            $t0 = microtime(true);
            $this->renderBladeBoundToSelf("native.{$name}", $viewData);
            $this->syncBladePolls(NativeElementCollector::takePollIntervals());
            $t1 = microtime(true);

            nphp_frame_end();
            $t2 = microtime(true);

            NativeRouter::debugLog(sprintf(
                'PERF streamView(%s) blade=%.1fms frame_end=%.1fms total=%.1fms',
                $name, ($t1 - $t0) * 1000, ($t2 - $t1) * 1000, ($t2 - $t0) * 1000
            ));
        } finally {
            NativeElementCollector::setStreaming(false);
        }
    }

    private function getPublicProperties(): array
    {
        $reflect = new \ReflectionClass($this);
        $props = [];

        foreach ($reflect->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (! $prop->isStatic()) {
                $props[$prop->getName()] = $prop->getValue($this);
            }
        }

        return $props;
    }

    // ── Computed properties (#[Computed]) ────────────

    /**
     * Lazily reflect #[Computed] methods into a name → metadata map.
     * The method name IS the property name (Livewire-style): a method
     * `revenue()` is read as `$this->revenue`.
     */
    private function computedMeta(): array
    {
        if ($this->computedMeta !== null) {
            return $this->computedMeta;
        }

        $meta = [];
        $reflect = new \ReflectionClass($this);

        foreach ($reflect->getMethods() as $method) {
            $attrs = $method->getAttributes(Computed::class);
            if ($attrs) {
                $name = $method->getName();
                $meta[$name] = [
                    'method' => $name,
                    'persist' => $attrs[0]->newInstance()->persist,
                ];
            }
        }

        return $this->computedMeta = $meta;
    }

    /**
     * Drop memoized computed values at the start of a frame, keeping any
     * declared `persist: true`. Called once per render-loop iteration.
     */
    private function resetComputedCache(): void
    {
        if (empty($this->computedCache)) {
            return;
        }

        $meta = $this->computedMeta();
        foreach (array_keys($this->computedCache) as $name) {
            if (! ($meta[$name]['persist'] ?? false)) {
                unset($this->computedCache[$name]);
            }
        }
    }

    /** Resolve `$this->foo` to a #[Computed] method (memoized per frame). */
    public function __get($name)
    {
        $meta = $this->computedMeta();

        if (isset($meta[$name])) {
            if (! array_key_exists($name, $this->computedCache)) {
                $this->computedCache[$name] = $this->{$meta[$name]['method']}();
            }

            return $this->computedCache[$name];
        }

        trigger_error(
            'Undefined property: '.static::class.'::$'.$name,
            E_USER_WARNING
        );

        return null;
    }

    /** So `isset($this->foo)` / Blade null-coalescing see computed props. */
    public function __isset($name): bool
    {
        return isset($this->computedMeta()[$name]);
    }

    /** `unset($this->foo)` busts a computed value (incl. persisted ones). */
    public function __unset($name): void
    {
        unset($this->computedCache[$name]);
    }

    // ── Polling (#[Poll]) ────────────────────────────

    /**
     * Lazily reflect #[Poll] attributes (class- and method-level) into a
     * list of due-tracked timers. Class-level polls have a null method
     * (re-render only); method-level polls invoke the method then re-render.
     */
    private function pollDefinitions(): array
    {
        if ($this->pollDefinitions !== null) {
            return $this->pollDefinitions;
        }

        $defs = [];
        $now = microtime(true) * 1000;
        $reflect = new \ReflectionClass($this);

        foreach ($reflect->getAttributes(Poll::class) as $attr) {
            $ms = $attr->newInstance()->ms;
            $defs[] = ['method' => null, 'ms' => $ms, 'next' => $now + $ms];
        }

        foreach ($reflect->getMethods() as $method) {
            foreach ($method->getAttributes(Poll::class) as $attr) {
                $ms = $attr->newInstance()->ms;
                $defs[] = ['method' => $method->getName(), 'ms' => $ms, 'next' => $now + $ms];
            }
        }

        return $this->pollDefinitions = $defs;
    }

    /**
     * Reconcile the Blade `native:poll` timers with the intervals declared
     * in the template this frame. New intervals get a fresh deadline;
     * intervals no longer present are dropped. Existing deadlines are left
     * intact so re-rendering (poll or user event) doesn't reset the clock.
     */
    private function syncBladePolls(array $intervals): void
    {
        $now = microtime(true) * 1000;

        foreach ($intervals as $ms) {
            if ($ms > 0 && ! isset($this->bladePollDeadlines[$ms])) {
                $this->bladePollDeadlines[$ms] = $now + $ms;
            }
        }

        foreach (array_keys($this->bladePollDeadlines) as $ms) {
            if (! in_array($ms, $intervals, true)) {
                unset($this->bladePollDeadlines[$ms]);
            }
        }
    }

    /**
     * Timeout (ms) to pass to `nativephp_element_wait_event`. Returns -1
     * (block indefinitely) when there are no polls (class #[Poll] or Blade
     * native:poll); otherwise the time until the soonest-due timer,
     * floored at 1ms.
     */
    private function nextEventTimeout(): int
    {
        $deadlines = array_map(fn ($def) => $def['next'], $this->pollDefinitions());
        foreach ($this->bladePollDeadlines as $next) {
            $deadlines[] = $next;
        }

        if (empty($deadlines)) {
            return -1;
        }

        return max(1, (int) ceil(min($deadlines) - microtime(true) * 1000));
    }

    /**
     * Fire any polls whose interval has elapsed, then reschedule them.
     * Called on an idle tick (wait_event returned null) before the loop
     * re-renders. Blade native:poll timers carry no callback — they just
     * trigger the re-render. Rescheduling off `$now` (not the prior
     * deadline) avoids catch-up storms after a long-blocked frame.
     */
    private function runDuePolls(): void
    {
        $now = microtime(true) * 1000;

        if (! empty($this->pollDefinitions)) {
            foreach ($this->pollDefinitions as $i => $def) {
                if ($now < $def['next']) {
                    continue;
                }

                if ($def['method'] !== null && method_exists($this, $def['method'])) {
                    $this->{$def['method']}();
                }

                $this->pollDefinitions[$i]['next'] = $now + $def['ms'];
            }
        }

        foreach ($this->bladePollDeadlines as $ms => $next) {
            if ($now >= $next) {
                $this->bladePollDeadlines[$ms] = $now + $ms;
            }
        }
    }

    // ── Lazy placeholder (#[Lazy]) ───────────────────

    /** Whether this component class is marked #[Lazy]. */
    private function isLazy(): bool
    {
        if ($this->lazy !== null) {
            return $this->lazy;
        }

        return $this->lazy = ! empty((new \ReflectionClass($this))->getAttributes(Lazy::class));
    }

    /**
     * The frame shown while a #[Lazy] component's mount() runs. Override
     * to provide a skeleton; the default is a centered activity indicator
     * wrapped in the screen's layout chrome.
     */
    protected function placeholder(): Element|View
    {
        return $this->wrapWithChrome(
            Column::make(ActivityIndicator::make())->fill()->center()
        );
    }

    /**
     * Publish the placeholder for a #[Lazy] component. Called by the
     * router (and standalone run()) right before mount(), so the screen
     * paints instantly while the heavy mount work proceeds. No-op for
     * non-lazy components, and runs at most once per instance.
     */
    public function publishPlaceholder(): void
    {
        if (! $this->isLazy() || $this->placeholderPublished) {
            return;
        }

        $this->placeholderPublished = true;
        $this->nativeCallbacks ??= new CallbackRegistry;
        $this->nativeCallbacks->reset();

        try {
            $result = $this->placeholder();
            $element = $result instanceof View
                ? $this->fromView($result)
                : $result;
            nativephp_element_publish($this->memoizedToArray($element));
        } catch (\Throwable $e) {
            NativeRouter::debugLog('placeholder() FAILED in '.static::class.': '.$e->getMessage());
        }
    }

    /**
     * Scan this component's methods for #[OnNative] attributes
     * and build the event name → method map.
     */
    private function registerNativeEventListeners(): void
    {
        $reflect = new \ReflectionClass($this);

        foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // `On` is the Livewire-free attribute; `OnNative` is kept for
            // backward compatibility (only instantiated when actually
            // present, so projects without Livewire never trip its
            // BaseOn parent).
            $attributes = [
                ...$method->getAttributes(On::class),
                ...$method->getAttributes(OnNative::class),
            ];

            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $this->nativeEventListeners[$instance->event] = $method->getName();
            }
        }
    }

    /**
     * Handle a native event (type 20) by looking up #[OnNative] listeners.
     */
    protected function dispatchNativeEvent(array $event): void
    {
        $eventName = $event['event'] ?? '';
        $payload = $event['payload'] ?? [];

        // Fire any fluent callback registered for this event
        // (e.g. Camera::getPhoto()->photoTaken(...)). Independent of #[On] — it must
        // run even when the component declares no listener for this event.
        $this->fireNativeCallback($eventName, is_array($payload) ? $payload : []);

        $method = $this->nativeEventListeners[$eventName]
            ?? $this->nativeEventListeners['native:'.$eventName]
            ?? null;

        if ($method === null || ! method_exists($this, $method)) {
            return;
        }

        if (is_array($payload)) {
            $reflect = new \ReflectionMethod($this, $method);
            $args = [];
            foreach ($reflect->getParameters() as $param) {
                $name = $param->getName();
                if (array_key_exists($name, $payload)) {
                    $value = $payload[$name];

                    // Coerce the value to match the parameter's type hint
                    $type = $param->getType();
                    if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                        $value = match ($type->getName()) {
                            'int' => (int) $value,
                            'float' => (float) $value,
                            'string' => (string) $value,
                            'bool' => (bool) $value,
                            default => $value,
                        };
                    }

                    $args[] = $value;
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                }
            }
            $this->$method(...$args);
        } else {
            $this->$method($payload);
        }
    }

    /**
     * Resolve and invoke a fluent callback (then()/catch()) correlated by the
     * event's `id`. Fires once, then drops the registration. Runs in the active
     * component's event loop, so the component re-renders afterward.
     */
    private function fireNativeCallback(string $eventName, array $payload): void
    {
        $id = $payload['id'] ?? null;

        // The incoming name is the raw event FQCN (the #[On] map adds the
        // native: prefix); normalise just in case it arrives prefixed.
        $eventClass = str_starts_with($eventName, 'native:')
            ? substr($eventName, 7)
            : $eventName;

        if (! class_exists($eventClass)) {
            return;
        }

        // Exact correlation by id (camera). If that misses — either no id came
        // back (some native paths drop it across a lifecycle bounce, e.g. the
        // gallery picker) or it didn't match — fall back to the single in-flight
        // callback for this event class.
        $callback = ($id !== null) ? NativeCallbacks::resolve($id, $eventClass) : null;

        if ($callback === null) {
            [$id, $callback] = NativeCallbacks::resolveByEvent($eventClass) ?? [null, null];
        }

        if ($callback === null) {
            return;
        }

        if (is_string($callback) && class_exists($callback)) {
            $callback = app($callback);
        }

        // Bind the closure to this live component so then()/catch() can use
        // $this (e.g. $this->images[] = ...). Static closures can't be bound, so
        // they keep running without $this.
        if ($callback instanceof \Closure && ! (new \ReflectionFunction($callback))->isStatic()) {
            $callback = \Closure::bind($callback, $this, static::class);
        }

        try {
            call_user_func($callback, $this->makeEventInstance($eventClass, $payload));
        } finally {
            // One outcome per capture — drop success/cancel/denied siblings too.
            NativeCallbacks::forget($id, $eventClass);
        }
    }

    /**
     * Build an event object from a native payload, binding constructor
     * parameters by name and tolerating extra/missing keys.
     */
    private function makeEventInstance(string $eventClass, array $payload): object
    {
        $constructor = (new \ReflectionClass($eventClass))->getConstructor();

        if ($constructor === null) {
            return new $eventClass;
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            }
        }

        return new $eventClass(...$args);
    }

    public function mount(): void
    {
        //
    }

    public function unmount(): void
    {
        //
    }

    public function onResume(): void
    {
        //
    }

    public function stop(): void
    {
        $this->nativeRunning = false;
    }

    /**
     * Called when the device back button is pressed.
     * Override in subclasses for custom behavior.
     * Default: navigate back.
     */
    public function onBackPressed(): void
    {
        $this->back();
    }

    public static function registerDumpHandler(): void
    {
        if (self::$dumpHandlerRegistered) {
            return;
        }

        self::$dumpHandlerRegistered = true;

        VarDumper::setHandler(function ($var) {
            $trace = debug_backtrace(0, 10);

            $ddFrame = null;
            foreach ($trace as $frame) {
                if (($frame['function'] ?? '') === 'dd') {
                    $ddFrame = $frame;
                    break;
                }
            }

            if ($ddFrame !== null) {
                // dd() call — grab all args and throw immediately
                $args = $ddFrame['args'] ?? [$var];
                $file = $ddFrame['file'] ?? 'unknown';
                $line = $ddFrame['line'] ?? 0;

                throw new NativeDumpException($args, $file, $line);
            }

            // Plain dump() call — log to file without throwing
            $cloner = new VarCloner;
            $dumper = new CliDumper;
            $dumper->setColors(false);

            $data = $cloner->cloneVar($var);
            $formatted = $dumper->dump($data, true);

            $logPath = storage_path('logs/edge-nav.log');
            @file_put_contents($logPath, '[dump] '.$formatted."\n", FILE_APPEND);
        });
    }

    /**
     * Full standalone lifecycle — init, mount, loop, unmount, shutdown.
     * Used when running without the NativeRouter.
     */
    public function run(): void
    {
        static::registerDumpHandler();

        $this->nativeCallbacks = new CallbackRegistry;
        $this->registerNativeEventListeners();

        // Phase 2 — every (re-)entry is a fresh session from the native
        // reader's perspective; discard any prior memo hashes.
        $this->lastNodeHashes = [];
        $this->publishCount = 0;

        nativephp_element_init();

        // For #[Lazy] screens, paint the placeholder before the
        // (potentially slow) mount() so the first frame is instant.
        $this->publishPlaceholder();

        try {
            $this->mount();
        } catch (NativeDumpException $e) {
            $this->renderDumpScreen($e);
        } catch (\Throwable $e) {
            NativeRouter::debugLog('mount() FAILED in '.static::class.': '.$e->getMessage());
            $this->renderErrorScreen($e);
        }

        while ($this->nativeRunning) {
            $this->nativeCallbacks->reset();
            $this->resetComputedCache();

            if (! $this->nativeHasError) {
                try {
                    if (! $this->renderStreaming()) {
                        $element = $this->renderToElement();
                        $tree = $this->memoizedToArray($element);
                        nativephp_element_publish($tree);
                    }
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('render() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }
            }

            $event = nativephp_element_wait_event($this->nextEventTimeout());

            if ($event === null) {
                // Idle tick (poll interval elapsed, or no event yet) —
                // fire any due polls, then loop back to re-render.
                $this->runDuePolls();

                continue;
            }

            // Hot reload: write restart signal and exit so Kotlin re-executes with fresh PHP
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->flushCompiledViews();
                // Prefer the native router's top-of-stack URI (where the
                // user actually IS after SPA-style internal navigation)
                // over `request()->path()` (the original HTTP entry-
                // point URI, typically `/`). Otherwise hot reload always
                // lands the user back on the root screen.
                $uri = $this->nativeRouter?->currentUri()
                    ?? '/'.ltrim(request()->path(), '/');
                // Serialize the full stack so back-button history
                // survives the reboot. `Route::native`'s handler reads
                // this on the way back in and preloads entries below
                // the top via `NativeRouter::preloadStack`.
                $stack = $this->nativeRouter?->getStackEntries() ?? [];
                @file_put_contents(
                    storage_path('framework/.hot_restart'),
                    json_encode(['uri' => $uri, 'stack' => $stack, 'ts' => time()])
                );
                $this->stop();

                continue;
            }

            // Native event from bridge function — dispatch to #[OnNative] listeners
            if (($event['type'] ?? -1) === self::EVENT_NATIVE) {
                try {
                    $this->dispatchNativeEvent($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('dispatchNativeEvent() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }

                continue;
            }

            // Don't dispatch UI events while showing the error/dump screen
            // (except overlay controls like font size buttons)
            if (! $this->nativeHasError) {
                try {
                    $this->dispatch($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('dispatch() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }
            } elseif (in_array($event['callback_id'] ?? 0, $this->overlayCallbackIds)) {
                $this->dispatch($event);
            }
        }

        $this->unmount();

        nativephp_element_shutdown();
    }

    /**
     * Just the render/event loop — no init/shutdown.
     * Used by NativeRouter for hot-swap navigation.
     */
    public function runLoop(): void
    {
        // The native runloop holds a single request open for the entire
        // lifetime of the screen — it blocks in `nativephp_element_wait_event()`
        // between user interactions and re-renders on every event. Under the
        // Jump dev server (`artisan serve`) the request inherits PHP's default
        // `max_execution_time = 30`, which counts the request's accumulated
        // run time. So a busy session (rapid taps, slider drags, navigation)
        // burns through 30s and PHP fatals mid-loop — at the blocking bridge
        // read in JumpBridge::readExact() — killing the request and freezing
        // the app. On-device there is no Symfony request lifecycle, so this
        // never bites there. Disable the limit: this loop is intentionally
        // long-running, exactly like the on-device runloop.
        @set_time_limit(0);

        // Jump hybrid mode only. The immortal request above is necessary on the
        // dev server but creates a hazard unique to Jump: when a WebView reload
        // or a re-scan opens a new `GET /`, the previous runloop's request is
        // abandoned by the client yet keeps running forever, publishing over the
        // SHARED device bridge and ping-ponging WaitEvents with the new session.
        // Claim this runloop as the current native session; the check at the top
        // of the loop makes any older, superseded runloop bail out so exactly one
        // ever drives the device.
        //
        // Gate on JUMP_BRIDGE_PORT (set only by `native:jump`). NOT on
        // `function_exists('nativephp_call')` — in Jump mode the PHP fallback
        // DEFINES that function, so it exists on both device and dev server and
        // would gate this off everywhere.
        $jumpSessionToken = null;
        if (getenv('JUMP_BRIDGE_PORT') !== false) {
            $jumpSessionToken = $this->claimJumpSession();
        }

        static::registerDumpHandler();

        $this->nativeCallbacks ??= new CallbackRegistry;
        $this->nativeRunning = true;
        $this->nativeNavigationIntent = null;

        // Phase 2 — every (re-)entry is a fresh session from the native
        // reader's perspective; discard any prior memo hashes. The
        // epoch handshake in memoizedToArray() handles mid-session
        // resets too, but clearing here ensures the very first publish
        // of this session can't emit REUSE.
        $this->lastNodeHashes = [];
        $this->publishCount = 0;

        if (empty($this->nativeEventListeners)) {
            $this->registerNativeEventListeners();
        }

        while ($this->nativeRunning) {
            // Superseded by a newer Jump native session — this runloop is an
            // orphan (its WebView is gone). The device-side bridge wakes us from
            // wait_event() via supersession; bail WITHOUT touching the bridge so
            // our teardown (unmount → element_shutdown) can't wipe the live
            // session's tree. mute() turns every subsequent bridge call into a
            // no-op; the null navigation intent then unwinds the stack quietly.
            if ($jumpSessionToken !== null && ! $this->isCurrentJumpSession($jumpSessionToken)) {
                if (class_exists(JumpBridge::class)) {
                    JumpBridge::instance()->mute();
                }
                $this->nativeRunning = false;
                break;
            }

            $this->nativeCallbacks->reset();
            $this->resetComputedCache();

            if (! $this->nativeHasError) {
                try {
                    $t0 = microtime(true);

                    if ($this->renderStreaming()) {
                        // Explicit streaming path
                        $this->nativeRouter?->flushDeferredTransition();
                        $t3 = microtime(true);
                        NativeRouter::debugLog(sprintf(
                            'PERF [%s] streaming total=%.1fms',
                            static::class, ($t3 - $t0) * 1000
                        ));
                    } else {
                        $element = $this->renderToElement();

                        $t1 = microtime(true);
                        $tree = $this->memoizedToArray($element);
                        $t2 = microtime(true);

                        $this->nativeRouter?->flushDeferredTransition();

                        nativephp_element_publish($tree);

                        $t3 = microtime(true);
                        NativeRouter::debugLog(sprintf(
                            'PERF [%s] render=%.1fms toArray=%.1fms publish=%.1fms total=%.1fms',
                            static::class, ($t1 - $t0) * 1000, ($t2 - $t1) * 1000,
                            ($t3 - $t2) * 1000, ($t3 - $t0) * 1000
                        ));
                    }
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('render() FAILED in '.static::class.': '.$e->getMessage()."\n".$e->getTraceAsString());
                    $this->renderErrorScreen($e);
                }
            }

            $event = nativephp_element_wait_event($this->nextEventTimeout());

            if ($event === null) {
                // Idle tick (poll interval elapsed, or no event yet) —
                // fire any due polls, then loop back to re-render.
                $this->runDuePolls();

                continue;
            }

            // Hot reload: write restart signal and exit so Kotlin re-executes with fresh PHP
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->flushCompiledViews();
                // Prefer the native router's top-of-stack URI (where the
                // user actually IS after SPA-style internal navigation)
                // over `request()->path()` (the original HTTP entry-
                // point URI, typically `/`). Otherwise hot reload always
                // lands the user back on the root screen.
                $uri = $this->nativeRouter?->currentUri()
                    ?? '/'.ltrim(request()->path(), '/');
                // Serialize the full stack so back-button history
                // survives the reboot. `Route::native`'s handler reads
                // this on the way back in and preloads entries below
                // the top via `NativeRouter::preloadStack`.
                $stack = $this->nativeRouter?->getStackEntries() ?? [];
                @file_put_contents(
                    storage_path('framework/.hot_restart'),
                    json_encode(['uri' => $uri, 'stack' => $stack, 'ts' => time()])
                );
                NativeRouter::debugLog("HOT_RELOAD: wrote restart signal for $uri (stack depth=".count($stack).')');
                $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::RESTART, $uri);
                $this->stop();

                continue;
            }

            // System back button (type 8)
            if (($event['type'] ?? -1) === 8) {
                if ($this->nativeHasError) {
                    // Dismiss error/dump screen and re-render the component
                    $this->nativeHasError = false;
                    $this->dumpException = null;
                    $this->errorException = null;
                    $this->overlayCallbackIds = [];

                    continue;
                }
                $this->onBackPressed();

                continue;
            }

            // Native event from bridge function — dispatch to #[OnNative] listeners
            if (($event['type'] ?? -1) === self::EVENT_NATIVE) {
                try {
                    $this->dispatchNativeEvent($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('dispatchNativeEvent() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }

                continue;
            }

            // Don't dispatch UI events while showing the error/dump screen
            // (except overlay controls like font size buttons)
            if (! $this->nativeHasError) {
                try {
                    $this->dispatch($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('dispatch() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }
            } elseif (in_array($event['callback_id'] ?? 0, $this->overlayCallbackIds)) {
                $this->dispatch($event);
            }
        }
    }

    public function getNavigationIntent(): ?NavigationIntent
    {
        return $this->nativeNavigationIntent;
    }

    /**
     * Shared marker naming the most recently started Jump native session.
     * One dev server drives one device, so a single file is sufficient.
     */
    protected function jumpSessionFile(): string
    {
        return sys_get_temp_dir().'/nativephp_jump_session';
    }

    /**
     * Stamp this runloop as the current Jump native session and return its
     * unique token. The last writer wins, so the newest `GET /` supersedes
     * every older runloop (which then exits via isCurrentJumpSession()).
     */
    protected function claimJumpSession(): string
    {
        $token = getmypid().'-'.hrtime(true).'-'.mt_rand(1000, 9999);
        @file_put_contents($this->jumpSessionFile(), $token, LOCK_EX);

        return $token;
    }

    /**
     * True while this runloop still owns the session marker. Fails open: a
     * missing/unreadable marker never kills the loop.
     */
    protected function isCurrentJumpSession(string $token): bool
    {
        $current = @file_get_contents($this->jumpSessionFile());

        return $current === false || $current === '' || $current === $token;
    }

    // ── Navigation methods ──────────────────────────

    public function navigate(string $uri, array $data = []): static
    {
        $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::NAVIGATE, $uri, $data);
        $this->publishFinalState();
        $this->stop();

        return $this;
    }

    public function back(): static
    {
        $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::BACK);
        $this->publishFinalState();
        $this->stop();

        return $this;
    }

    public function replace(string $uri, array $data = []): static
    {
        $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::REPLACE, $uri, $data);
        $this->publishFinalState();
        $this->stop();

        return $this;
    }

    /**
     * Re-render and publish ONE more time before stopping the runloop.
     *
     * The runloop is `render → wait → dispatch → loop`. State mutations
     * inside a handler that ALSO calls `navigate()` / `back()` /
     * `replace()` happen AFTER the iteration's render, so they never
     * reach the renderer — the loop exits via `stop()` before looping
     * back to render again.
     *
     * Without this, a handler that closes a bottom sheet AND navigates
     * (`$this->showNewMessage = false; $this->navigate(...)`) leaves
     * iOS / Compose with the pre-mutation tree on screen — sheet stays
     * visible over the newly-pushed view. Worse, the sheet's later
     * onDismiss callback ID lands on the wrong active component (see
     * the ID-collision rationale in `CallbackRegistry`) and can fire an
     * arbitrary method, with destructive consequences when the
     * collided ID maps to something like `confirmClearHistory`.
     *
     * One extra render+publish per navigation is negligible compared
     * to the wire/diff cost of every other publish.
     */
    protected function publishFinalState(): void
    {
        if ($this->nativeHasError) {
            return;
        }
        try {
            $element = $this->renderToElement();
            $tree = $element->toArray($this->nativeCallbacks);
            nativephp_element_publish($tree);
        } catch (\Throwable $e) {
            // Render failure on the way out — outgoing component is
            // about to unmount anyway, swallow.
            NativeRouter::debugLog('publishFinalState failed in '.static::class.': '.$e->getMessage());
        }
    }

    public function transition(Transition $type): static
    {
        if ($this->nativeNavigationIntent) {
            $this->nativeNavigationIntent = new NavigationIntent(
                $this->nativeNavigationIntent->type,
                $this->nativeNavigationIntent->uri,
                $this->nativeNavigationIntent->data,
                $type,
            );
        }

        return $this;
    }

    public function exitToWeb(string $uri): void
    {
        $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::EXIT_WEB, $uri);
        $this->stop();
    }

    // ── Route helper ────────────────────────────────

    /**
     * Resolve a named route to a URI path for native navigation.
     *
     *   $this->navigate($this->route('listing.show', ['id' => 5]));
     */
    public function route(string $name, mixed $parameters = []): string
    {
        return URL::route($name, $parameters, absolute: false);
    }

    // ── Parameter / data access ─────────────────────

    public function param(string $key, $default = null)
    {
        return $this->nativeParams[$key] ?? $default;
    }

    public function data(string $key, $default = null)
    {
        return $this->nativeNavigationData[$key] ?? $default;
    }

    // ── Injection (called by NativeRouter) ──────────

    public function setRouter(NativeRouter $router): void
    {
        $this->nativeRouter = $router;
    }

    public function setParams(array $params): void
    {
        $this->nativeParams = $params;
    }

    public function setData(array $data): void
    {
        $this->nativeNavigationData = $data;
    }

    // ── Element resolution helper ──────────────────

    private function resolveElement(string $type, array $attrs = []): ?Element
    {
        $el = ElementRegistry::resolve($type);

        if ($el !== null) {
            $el->applyAttributes($attrs);
        }

        return $el;
    }

    // ── Error screen ────────────────────────────────

    public function renderErrorScreen(\Throwable $e): void
    {
        $this->nativeHasError = true;
        $this->errorException = $e;
        $this->nativeCallbacks ??= new CallbackRegistry;

        try {
            $screen = Elements\ScrollView::make()
                ->fill()
                ->bg('#FEF2F2')
                ->safeArea();

            $content = Column::make()
                ->fillWidth()
                ->padding(20, 20, 40, 20)
                ->gap(10);

            $title = $this->resolveElement('text', ['text' => 'Exception', 'fontSize' => 22, 'fontWeight' => 7, 'color' => '#991B1B']);
            if ($title) {
                $content->addChild($title);
            }

            $file = str_replace(base_path().'/', '', $e->getFile());
            $location = $this->resolveElement('text', ['text' => "{$file}:{$e->getLine()}", 'fontSize' => 12, 'color' => '#9CA3AF']);
            if ($location) {
                $content->addChild($location);
            }

            // Font size slider
            $slider = $this->resolveElement('slider', ['value' => (float) $this->overlayFontSize, 'min' => 6, 'max' => 40, 'step' => 2, 'color' => '#DC2626', 'trackColor' => '#991B1B']);
            if ($slider) {
                if (method_exists($slider, 'onChange')) {
                    $slider->onChange('__overlaySetFontSize');
                }
                $content->addChild($slider->fillWidth());
            }

            $divider = $this->resolveElement('divider');
            if ($divider) {
                $content->addChild($divider->fillWidth());
            }

            $className = $this->resolveElement('text', ['text' => static::class, 'fontSize' => 13, 'color' => '#B91C1C']);
            if ($className) {
                $content->addChild($className);
            }

            $message = $this->resolveElement('text', ['text' => $e->getMessage(), 'fontSize' => $this->overlayFontSize, 'fontWeight' => 5, 'color' => '#DC2626']);
            if ($message) {
                $content->addChild($message);
            }

            // Show a condensed stack trace
            $trace = $e->getTraceAsString();
            $trace = str_replace(base_path().'/', '', $trace);
            $traceLines = explode("\n", $trace);
            $shortTrace = implode("\n", array_slice($traceLines, 0, 15));
            if (count($traceLines) > 15) {
                $shortTrace .= "\n... (".count($traceLines).' frames total)';
            }

            $traceText = $this->resolveElement('text', ['text' => $shortTrace, 'fontSize' => $this->overlayFontSize, 'color' => '#6B7280']);
            if ($traceText) {
                $content->addChild($traceText);
            }

            $screen->addChild($content);

            $errorTree = $screen->toArray($this->nativeCallbacks);

            $this->overlayCallbackIds = array_filter([
                $this->nativeCallbacks->lookup('__overlaySetFontSize'),
            ]);

            $this->nativeRouter?->flushDeferredTransition();
            nativephp_element_publish($errorTree);
        } catch (\Throwable $renderError) {
            NativeRouter::debugLog('Error screen render failed: '.$renderError->getMessage());
        }
    }

    // ── Dump screen (dd) ─────────────────────────────

    public function renderDumpScreen(NativeDumpException $e): void
    {
        $this->nativeHasError = true;
        $this->dumpException = $e;
        $this->nativeCallbacks ??= new CallbackRegistry;

        try {
            $screen = Elements\ScrollView::make()
                ->fill()
                ->bg('#0F172A')
                ->safeArea();

            $content = Column::make()
                ->fillWidth()
                ->padding(20, 20, 40, 20)
                ->gap(10);

            $title = $this->resolveElement('text', ['text' => 'dd()', 'fontSize' => 22, 'fontWeight' => 7, 'color' => '#22D3EE']);
            if ($title) {
                $content->addChild($title);
            }

            $file = str_replace(base_path().'/', '', $e->getSourceFile());
            $location = $this->resolveElement('text', ['text' => "{$file}:{$e->getSourceLine()}", 'fontSize' => 12, 'color' => '#64748B']);
            if ($location) {
                $content->addChild($location);
            }

            // Font size slider
            $slider = $this->resolveElement('slider', ['value' => (float) $this->overlayFontSize, 'min' => 6, 'max' => 40, 'step' => 2, 'color' => '#22D3EE', 'trackColor' => '#164E63']);
            if ($slider) {
                if (method_exists($slider, 'onChange')) {
                    $slider->onChange('__overlaySetFontSize');
                }
                $content->addChild($slider->fillWidth());
            }

            $divider = $this->resolveElement('divider');
            if ($divider) {
                $content->addChild($divider->fillWidth());
            }

            $dumpText = $this->resolveElement('text', ['text' => $e->getFormattedDumps(), 'fontSize' => $this->overlayFontSize, 'color' => '#E2E8F0']);
            if ($dumpText) {
                $content->addChild($dumpText);
            }

            $screen->addChild($content);

            $dumpTree = $screen->toArray($this->nativeCallbacks);

            $this->overlayCallbackIds = array_filter([
                $this->nativeCallbacks->lookup('__overlaySetFontSize'),
            ]);

            $this->nativeRouter?->flushDeferredTransition();
            nativephp_element_publish($dumpTree);
        } catch (\Throwable $renderError) {
            NativeRouter::debugLog('Dump screen render failed: '.$renderError->getMessage());
        }
    }

    // ── Overlay font size control (shared by dump + error screens) ──

    public function __overlaySetFontSize(float $size): void
    {
        $this->overlayFontSize = (int) max(6, min(40, $size));

        if ($this->dumpException) {
            $this->renderDumpScreen($this->dumpException);
        } elseif ($this->errorException) {
            $this->renderErrorScreen($this->errorException);
        }
    }

    // ── Hot reload ──────────────────────────────────

    protected function flushCompiledViews(): void
    {
        $viewPath = storage_path('framework/views');

        if (is_dir($viewPath)) {
            foreach (glob("{$viewPath}/*.php") as $file) {
                // Skip .blade.php source files — these are created by
                // Laravel's createBladeViewFromString() for inline component
                // views (e.g. self-closing components returning ''). Deleting
                // them causes "View [hash] not found" errors.
                if (str_ends_with($file, '.blade.php')) {
                    continue;
                }

                @unlink($file);
            }
        }

        // Critical: clear stat cache AFTER deleting files so PHP sees
        // the deletions. Long-running processes cache stat() results,
        // and Blade's isExpired() uses file_exists() / filemtime().
        clearstatcache();

        // Clear the view finder cache so Blade re-discovers templates
        if (function_exists('app') && app()->bound('view')) {
            app('view')->getFinder()->flush();
        }

        // Reset OPcache if available — the long-running process may
        // have cached bytecode for the old compiled views
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    // ── Declarative navigation ────────────────────

    public function __navigate(string $key): void
    {
        $config = $this->nativeCallbacks->resolveNavigation($key);

        if ($config === null) {
            return;
        }

        match ($config['type']) {
            'back' => $this->back(),
            'replace' => $this->replace($config['uri'] ?? '', $config['data'] ?? []),
            'exitToWeb' => $this->exitToWeb($config['uri'] ?? ''),
            default => $this->navigate($config['uri'] ?? '', $config['data'] ?? []),
        };

        if (($config['transition'] ?? null) !== null) {
            $this->transition(Transition::from($config['transition']));
        }
    }

    // ── Model binding ──────────────────────────────

    public function __syncProperty(string $property, mixed $value): void
    {
        if (! property_exists($this, $property)) {
            return;
        }

        $this->{$property} = $value;

        // A state change can invalidate any computed value (incl.
        // persisted ones) — drop the whole memo so they recompute.
        $this->computedCache = [];

        $hook = 'updated'.ucfirst($property);

        if (method_exists($this, $hook)) {
            $this->{$hook}($value);
        }
    }

    // ── Event dispatch ──────────────────────────────

    protected function dispatch(array $event): void
    {
        $callback = $this->nativeCallbacks->resolve($event['callback_id'] ?? 0);

        if ($callback === null) {
            return;
        }

        $method = $callback['method'];
        $args = $callback['args'];

        if (! method_exists($this, $method)) {
            return;
        }

        $type = $event['type'] ?? -1;

        $eventArgs = match ($type) {
            2, 4 => [$event['text'] ?? ''],                      // TEXT_CHANGE, SUBMIT
            3, 10 => [(bool) ($event['value'] ?? false)],          // TOGGLE_CHANGE, CHECKBOX_CHANGE
            9 => [(float) ($event['value'] ?? 0.0)],           // SLIDER_CHANGE
            11, 12 => [(string) ($event['value'] ?? '')],           // RADIO_CHANGE, SELECT_CHANGE
            13 => [(int) ($event['value'] ?? 0)],               // TAB_CHANGE
            default => [],                                           // PRESS, LONG_PRESS, SHEET_DISMISS
        };

        // Dispatch path forks based on callback kind. Default kind
        // (null) is fire-and-forget — return value is discarded.
        // `search_query` kind captures the `array` return into
        // `$pendingSearchResults`, which the next publish reads as
        // the new `nav_search_items` corpus.
        $kind = $this->nativeCallbacks->kind($event['callback_id'] ?? 0);

        if ($kind === 'search_query') {
            $result = $this->$method(...[...$args, ...$eventArgs]);
            if (is_array($result)) {
                $this->pendingSearchResults = array_values($result);
            }

            return;
        }

        // 'virtual_window' callbacks ride on the TEXT_CHANGE event format
        // (the C extension parses payloads by event type — reusing
        // TEXT_CHANGE keeps the extension untouched). Native packs
        // "from,to" as the text; we decode and pass two ints.
        if ($kind === 'virtual_window') {
            $payload = $event['text'] ?? '';
            $parts = explode(',', $payload, 2);
            $from = (int) ($parts[0] ?? 0);
            $to = (int) ($parts[1] ?? 0);
            $this->$method(...[...$args, $from, $to]);

            return;
        }

        $this->$method(...[...$args, ...$eventArgs]);
    }
}
