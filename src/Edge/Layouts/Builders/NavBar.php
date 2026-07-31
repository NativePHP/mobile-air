<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Image;
use Native\Mobile\Edge\Elements\TopBar;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Elements\TopBarTitle;

/**
 * Fluent builder for the top navigation bar.
 *
 * Layouts return a NavBar instance from their navBar() method:
 *
 *   public function navBar(NativeComponent $screen): ?NavBar
 *   {
 *       return NavBar::make()
 *           ->title($screen->navTitle())
 *           ->back();
 *   }
 *
 * The framework converts this to an Elements\TopBar before publishing.
 */
class NavBar
{
    private ?string $title = null;

    private ?string $subtitle = null;

    /**
     * Optional element / Blade view rendered in the bar's centered "principal"
     * slot in place of the string title — a logo, wordmark, or any custom
     * lockup. When set, it wins over {@see title()} (iOS renders it as a
     * `.principal` toolbar item and suppresses `.navigationTitle`; Android
     * renders it in the `TopAppBar` title slot). Best paired with inline
     * display mode.
     */
    private Element|View|null $titleView = null;

    private bool $back = false;

    private ?string $backgroundColor = null;

    private ?string $textColor = null;

    private ?string $font = null;

    private ?int $elevation = null;

    /**
     * Title display mode — controls how iOS NavigationStack renders the
     * title. Valid: `inline` (small, centered — current default),
     * `large` (big, left-aligned, collapses on scroll), `automatic`
     * (large at root, inline once pushed).
     */
    private ?string $displayMode = null;

    /**
     * How the top bar reacts to content scrolling. Valid: `collapse`
     * (large title shrinks to the small bar as content scrolls under
     * it, leaving the search field pinned), `pinned` (bar is fixed —
     * nothing collapses), `enterAlways` (the whole bar slides off on
     * scroll-down and returns on any scroll-up). Null falls back to the
     * legacy default: `collapse` when `displayMode('large')`, else
     * `pinned`.
     */
    private ?string $scrollBehavior = null;

    /** @var NavAction[] */
    private array $actions = [];

    /**
     * Pre-built `top_bar_action` elements adopted from an inline
     * `<native:top-bar>` (see {@see fromElement}). Kept as elements —
     * not converted back to NavAction builders — so callbacks the
     * collector already wired (`@tap` → pressMethod, `:url` navigation)
     * survive untouched. Emitted after the builder actions by
     * {@see actionElements}.
     *
     * @var TopBarAction[]
     */
    private array $prebuiltActionElements = [];

    /**
     * Inline search bar config — same shape as
     * [NavBarOptions::$searchBar]. Set via [searchBar] or merged in
     * via [mergeOptions]. Folded into the chrome sentinel as
     * `nav_search_*` wire props; iOS attaches `.searchable` to the
     * destination, Android attaches an M3 search field to the top
     * app bar slot.
     *
     * @var array{placeholder?: string, onQuery?: ?string, debounceMs?: int}|null
     */
    private ?array $searchBar = null;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Reconstruct a NavBar builder from a collected `top_bar` element —
     * the bridge that lets an inline `<native:top-bar>` in a screen's
     * blade drive the SAME native-chrome path (NativeRootStack /
     * NativeRootTabs props) the layout builders feed, instead of being
     * drawn as an in-tree element.
     *
     * Maps the element's snake_case props back onto builder state and
     * adopts its `top_bar_action` children as pre-built elements
     * (preserving any collector-wired callbacks / navigate configs).
     */
    public static function fromElement(TopBar $element): self
    {
        $bar = new self;
        $props = $element->getRawProps();

        $bar->title = $props['title'] ?? null;
        $bar->subtitle = $props['subtitle'] ?? null;
        $bar->back = (bool) ($props['show_navigation_icon'] ?? false);
        $bar->backgroundColor = $props['background_color'] ?? null;
        $bar->textColor = $props['text_color'] ?? null;
        $bar->font = $props['font_name'] ?? null;
        $bar->elevation = isset($props['elevation']) ? (int) $props['elevation'] : null;
        $bar->displayMode = $props['display_mode'] ?? null;
        $bar->scrollBehavior = $props['scroll_behavior'] ?? null;

        if (isset($props['search_placeholder']) || isset($props['search_on_query'])) {
            $bar->searchBar = [
                'placeholder' => $props['search_placeholder'] ?? '',
                'onQuery' => $props['search_on_query'] ?? null,
                'debounceMs' => (int) ($props['search_debounce_ms'] ?? 300),
            ];
        }

        foreach ($element->getChildren() as $child) {
            if ($child instanceof TopBarAction) {
                $bar->prebuiltActionElements[] = $child;
            }
            // An inline `<native:top-bar-title>` is the blade spelling of
            // `titleView()`. Kept as the collected marker element (callbacks
            // its children carry survive); NativeComponent emits it as-is
            // rather than re-wrapping.
            if ($child instanceof TopBarTitle) {
                $bar->titleView = $child;
            }
        }

        return $bar;
    }

    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Render a custom element / Blade view in the bar's centered principal slot
     * instead of the string title — a logo lockup, wordmark, etc. Takes any
     * element tree or a Blade view (rendered against the screen), so it's not
     * limited to images.
     *
     *   ->titleView(Image::make('images/logo.png')->height(24))
     *   ->titleView(view('native.brand-lockup'))
     */
    public function titleView(Element|View $content): self
    {
        $this->titleView = $content;

        return $this;
    }

    /**
     * Convenience over {@see titleView()} for the common case: a bundled logo
     * image, sized to a sensible nav-bar height. `$src` is passed straight to
     * the `Image` element (bundle an app asset rather than a remote URL so the
     * bar doesn't flash-load).
     *
     *   ->logo('images/jump-logo.png')
     */
    public function logo(string $src, float $height = 28): self
    {
        return $this->titleView(Image::make($src)->height($height));
    }

    public function subtitle(?string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function back(bool $show = true): self
    {
        $this->back = $show;

        return $this;
    }

    public function backgroundColor(string $color): self
    {
        $this->backgroundColor = $color;

        return $this;
    }

    public function textColor(string $color): self
    {
        $this->textColor = $color;

        return $this;
    }

    /**
     * Render the bar's title/subtitle in a custom font — a resources/fonts/
     * file token (e.g. `Inter-Bold`). Overrides the layout's `$font` and the
     * theme's app-wide `font-family` default. iOS note: system-drawn LARGE
     * titles keep the app-default font (UIKit limitation); inline titles,
     * subtitles, and Android honor this fully.
     */
    public function font(string $name): self
    {
        $this->font = $name;

        return $this;
    }

    /**
     * Layout-wide default (from NativeLayout::$font) — applies only when no
     * explicit ->font() was set on this bar.
     */
    public function defaultFont(?string $name): self
    {
        if ($this->font === null && $name !== null) {
            $this->font = $name;
        }

        return $this;
    }

    public function elevation(int $px): self
    {
        $this->elevation = $px;

        return $this;
    }

    /**
     * Title display mode. `large` gives the iOS-native large-title-on-top,
     * left-aligned, collapses to inline when content scrolls beneath.
     * `inline` is the small centered title. `automatic` lets iOS pick
     * (large at the root of a stack, inline after a push).
     *
     *   ->displayMode('large')   // big left-aligned hero title
     *   ->displayMode('inline')  // current default
     *   ->displayMode('automatic')
     */
    public function displayMode(string $mode): self
    {
        $this->displayMode = $mode;

        return $this;
    }

    /**
     * Control how the bar responds to content scrolling.
     *
     *   ->scrollBehavior('collapse')    // large title collapses to the
     *                                   // small bar; search field stays
     *                                   // pinned beneath it (iOS large-
     *                                   // title parity).
     *   ->scrollBehavior('pinned')      // bar is fixed — nothing moves.
     *   ->scrollBehavior('enterAlways') // bar slides off on scroll-down,
     *                                   // returns on any scroll-up.
     *
     * Android: maps to Material 3 `exitUntilCollapsed` / `pinned` /
     * `enterAlways` `TopAppBarScrollBehavior`s. iOS: drives whether the
     * `.searchable` field stays pinned (`.navigationBarDrawer(.always)`)
     * or tucks away (`.automatic`); the large title's own collapse is
     * the system default for `displayMode('large')`.
     */
    public function scrollBehavior(string $mode): self
    {
        $this->scrollBehavior = $mode;

        return $this;
    }

    public function action(NavAction $action): self
    {
        $this->actions[] = $action;

        return $this;
    }

    /**
     * Attach an inline search field to this nav bar. Apple HIG / Expo
     * Router pattern. See [NavBarOptions::searchBar] for the full doc.
     */
    public function searchBar(
        string $placeholder = '',
        ?string $onQuery = null,
        int $debounceMs = 300,
    ): self {
        $this->searchBar = [
            'placeholder' => $placeholder,
            'onQuery' => $onQuery,
            'debounceMs' => $debounceMs,
        ];

        return $this;
    }

    /**
     * Apply a NavBarOptions struct from a screen's navigationOptions().
     * Non-null fields on $opts override our values.
     */
    public function mergeOptions(?NavBarOptions $opts): self
    {
        if ($opts === null) {
            return $this;
        }
        if ($opts->title !== null) {
            $this->title = $opts->title;
        }
        if ($opts->subtitle !== null) {
            $this->subtitle = $opts->subtitle;
        }
        if ($opts->back !== null) {
            $this->back = $opts->back;
        }
        if ($opts->backgroundColor !== null) {
            $this->backgroundColor = $opts->backgroundColor;
        }
        if ($opts->textColor !== null) {
            $this->textColor = $opts->textColor;
        }
        if ($opts->font !== null) {
            $this->font = $opts->font;
        }
        if ($opts->elevation !== null) {
            $this->elevation = $opts->elevation;
        }
        if ($opts->displayMode !== null) {
            $this->displayMode = $opts->displayMode;
        }
        if ($opts->scrollBehavior !== null) {
            $this->scrollBehavior = $opts->scrollBehavior;
        }
        if ($opts->searchBar !== null) {
            $this->searchBar = $opts->searchBar;
        }
        foreach ($opts->actions as $action) {
            $this->actions[] = $action;
        }

        return $this;
    }

    /**
     * Apply imperative state from a screen's $this->setNavBar([...]) call.
     * Same precedence as mergeOptions but takes an array.
     */
    public function mergeState(array $state): self
    {
        if (isset($state['title'])) {
            $this->title = $state['title'];
        }
        if (isset($state['subtitle'])) {
            $this->subtitle = $state['subtitle'];
        }
        if (isset($state['back'])) {
            $this->back = (bool) $state['back'];
        }
        if (isset($state['backgroundColor'])) {
            $this->backgroundColor = $state['backgroundColor'];
        }
        if (isset($state['textColor'])) {
            $this->textColor = $state['textColor'];
        }
        if (isset($state['font'])) {
            $this->font = $state['font'];
        }
        if (isset($state['elevation'])) {
            $this->elevation = (int) $state['elevation'];
        }
        if (isset($state['displayMode'])) {
            $this->displayMode = $state['displayMode'];
        }
        if (isset($state['scrollBehavior'])) {
            $this->scrollBehavior = $state['scrollBehavior'];
        }

        return $this;
    }

    /**
     * Serialize the bar's declarative config as an attrs dict suitable
     * for `NativeRootStack::applyAttributes()` (camelCase keys: `title`,
     * `subtitle`, `back`, `backgroundColor`, `textColor`, `elevation`,
     * `displayMode`). Used by the native-chrome rollout path in
     * `NativeComponent::wrapWithNativeChrome()`.
     */
    public function toRootProps(): array
    {
        $attrs = ['back' => $this->back];
        if ($this->title !== null) {
            $attrs['title'] = $this->title;
        }
        if ($this->subtitle !== null) {
            $attrs['subtitle'] = $this->subtitle;
        }
        if ($this->backgroundColor !== null) {
            $attrs['backgroundColor'] = $this->backgroundColor;
        }
        if ($this->textColor !== null) {
            $attrs['textColor'] = $this->textColor;
        }
        if ($this->font !== null) {
            $attrs['fontName'] = $this->font;
        }
        if ($this->elevation !== null) {
            $attrs['elevation'] = $this->elevation;
        }
        if ($this->displayMode !== null) {
            $attrs['displayMode'] = $this->displayMode;
        }
        if ($this->scrollBehavior !== null) {
            $attrs['scrollBehavior'] = $this->scrollBehavior;
        }
        if ($this->searchBar !== null) {
            $attrs['searchPlaceholder'] = $this->searchBar['placeholder'] ?? '';
            if (! empty($this->searchBar['onQuery'])) {
                // Method name as a string — the chrome sentinel
                // (NativeRootStack / NativeRootTabs) registers it with
                // its own CallbackRegistry inside `resolveProps()` so
                // the wire carries a numeric callback id.
                $attrs['searchOnQuery'] = $this->searchBar['onQuery'];
            }
            $attrs['searchDebounceMs'] = $this->searchBar['debounceMs'] ?? 300;
        }

        return $attrs;
    }

    /** @return NavAction[] */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Every action as a ready-to-attach `top_bar_action` element —
     * builder-declared actions first, then any pre-built elements adopted
     * from an inline `<native:top-bar>`. This is what the chrome wrapper
     * attaches to the native root, so both sources compose uniformly.
     *
     * @return TopBarAction[]
     */
    public function actionElements(): array
    {
        return [
            ...array_map(fn (NavAction $action) => $action->toElement(), $this->actions),
            ...$this->prebuiltActionElements,
        ];
    }

    /** The custom principal-slot content (element or Blade view), or null. */
    public function getTitleView(): Element|View|null
    {
        return $this->titleView;
    }

    public function toElement(): TopBar
    {
        $bar = TopBar::make();

        $attrs = ['showNavigationIcon' => $this->back];
        if ($this->title !== null) {
            $attrs['title'] = $this->title;
        }
        if ($this->subtitle !== null) {
            $attrs['subtitle'] = $this->subtitle;
        }
        if ($this->backgroundColor !== null) {
            $attrs['backgroundColor'] = $this->backgroundColor;
        }
        if ($this->textColor !== null) {
            $attrs['textColor'] = $this->textColor;
        }
        if ($this->font !== null) {
            $attrs['fontName'] = $this->font;
        }
        if ($this->elevation !== null) {
            $attrs['elevation'] = $this->elevation;
        }

        $bar->applyAttributes($attrs);

        foreach ($this->actionElements() as $action) {
            $bar->addChild($action);
        }

        return $bar;
    }
}
