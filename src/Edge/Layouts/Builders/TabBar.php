<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use InvalidArgumentException;
use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;

/**
 * Fluent builder for the bottom tab bar.
 *
 * Layouts return a TabBar from their tabBar() method:
 *
 *   public function tabBar(NativeComponent $screen): ?TabBar
 *   {
 *       return TabBar::make()
 *           ->add(Tab::link('Home',    '/',        icon: 'home'))
 *           ->add(Tab::link('Browse',  '/browse',  icon: 'search'))
 *           ->add(Tab::link('Profile', '/profile', icon: 'person'));
 *   }
 *
 * The framework converts this to an Elements\BottomNav. Tab items
 * already auto-wire navigation from their `url` attribute via
 * BottomNavItem::resolveProps().
 *
 * Leave {@see hidden()} unset and the bar decides from the screen it is
 * drawn on (see {@see forScreen()}): it hides on a child route
 * (`Route::native(...)->children(...)`) and shows everywhere else. Who
 * decides, from strongest to weakest:
 *
 *  1. The screen's own say: its `tabBarOptions()->hidden(...)`, or
 *     `protected bool $hidesTabBar = true`. These apply to every tab
 *     bar, the layout's and an inline `<native:bottom-nav>` alike.
 *  2. This bar's explicit value: `->hidden(true|false)`, or a `hidden`
 *     attribute on an inline `<native:bottom-nav>`
 *     (`<native:bottom-nav :hidden="false">` keeps it on a child screen).
 *  3. The bar's default from the screen's route: hidden on a child route.
 */
class TabBar
{
    public const MAX_PRESENTATION_IDENTIFIER_LENGTH = 128;

    public const MAX_PRESENTATION_CONFIGURATION_BYTES = 16384;

    public const MAX_PRESENTATION_CONFIGURATION_DEPTH = 8;

    /** @var Tab[] */
    private array $tabs = [];

    /**
     * Pre-built `bottom_nav_item` elements adopted from an inline
     * `<native:bottom-nav>` (see {@see fromElement}). Kept as elements
     * so collector-wired callbacks (`@tap` press handlers, `:url`
     * navigation) survive. Emitted after the builder tabs by
     * {@see tabElements}.
     *
     * @var BottomNavItem[]
     */
    private array $prebuiltTabElements = [];

    private ?string $activeColor = null;

    private ?string $textColor = null;

    private ?string $font = null;

    private ?string $backgroundColor = null;

    private ?string $labelVisibility = null;

    private bool $dark = false;

    private bool $minimizeOnScroll = false;

    private ?string $presentationIdentifier = null;

    private ?string $presentationConfiguration = null;

    /**
     * Hidden (true) or kept (false) on purpose, or null to let the screen
     * decide. See the class docblock.
     */
    private ?bool $hidden = null;

    /**
     * What the screen itself says through its tab bar knobs, or null when
     * it says nothing. Set by {@see forScreen()}.
     */
    private ?bool $hiddenByScreen = null;

    /** Whether the screen this bar is drawn on is a child route. Set by {@see forScreen()}. */
    private bool $onChildRoute = false;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Reconstruct a TabBar builder from a collected `bottom_nav` element —
     * the bridge that lets an inline `<native:bottom-nav>` in a screen's
     * blade drive the SAME native-chrome path (NativeRootTabs props) the
     * layout builders feed, instead of being drawn as an in-tree element.
     *
     * Maps the element's snake_case props back onto builder state and
     * adopts its `bottom_nav_item` children as pre-built elements
     * (preserving collector-wired callbacks / navigate configs).
     */
    public static function fromElement(BottomNav $element): self
    {
        $bar = new self;
        $props = $element->getRawProps();

        $bar->dark = (bool) ($props['dark'] ?? false);
        $bar->labelVisibility = $props['label_visibility'] ?? null;
        $bar->activeColor = $props['active_color'] ?? null;
        $bar->backgroundColor = $props['background_color'] ?? null;
        $bar->textColor = $props['text_color'] ?? null;
        $bar->font = $props['font_name'] ?? null;
        $bar->minimizeOnScroll = (bool) ($props['minimize_on_scroll'] ?? false);
        $bar->presentationIdentifier = $props['tab_presentation_id'] ?? null;
        $bar->presentationConfiguration = $props['tab_presentation_configuration'] ?? null;
        // Null when the blade wrote no `hidden`, so the route decides.
        $bar->hidden = $element->hiddenAttribute();

        foreach ($element->getChildren() as $child) {
            if ($child instanceof BottomNavItem) {
                $bar->prebuiltTabElements[] = $child;
            }
        }

        return $bar;
    }

    public function add(Tab $tab): self
    {
        $this->tabs[] = $tab;

        return $this;
    }

    public function activeColor(string $color): self
    {
        $this->activeColor = $color;

        return $this;
    }

    /**
     * Explicit bar background color. Overrides whatever bg `dark()` would
     * pick. Hex strings (e.g. `#0F172A`).
     */
    public function backgroundColor(string $color): self
    {
        $this->backgroundColor = $color;

        return $this;
    }

    /**
     * Color for inactive tab icons + labels. Overrides the gray defaults
     * picked by `dark()`. Active tabs continue to use `activeColor()`.
     *
     * Android: fully honored. iOS: the unselected-item tint is only
     * settable via `UITabBarAppearance`, which iOS 26 ignores for Liquid
     * Glass tab bars — so on iOS 26 inactive tabs keep the system color
     * and this is effectively a no-op.
     */
    public function textColor(string $color): self
    {
        $this->textColor = $color;

        return $this;
    }

    /**
     * Render tab labels in a custom font — a resources/fonts/ file token
     * (e.g. `Inter-Bold`). Overrides the layout's `$font` and the theme's
     * app-wide `font-family` default.
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

    /**
     * One of "labeled" (default), "selected" (only active shows label),
     * or "unlabeled" (icons only).
     */
    public function labelVisibility(string $mode): self
    {
        $this->labelVisibility = $mode;

        return $this;
    }

    public function dark(bool $dark = true): self
    {
        $this->dark = $dark;

        return $this;
    }

    /**
     * Hide the bar on purpose (true), keep it on purpose (false), or hand
     * the choice back to the screen's route (null). An explicit value
     * beats the route default, so `->hidden(false)` keeps the bar on a
     * child screen. The screen's own `tabBarOptions()->hidden()` and
     * `$hidesTabBar = true` still beat it.
     */
    public function hidden(?bool $hidden = true): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Tell the bar which screen it is drawn on. The bar reads two things
     * from it: what the screen says about its own tab bar (its
     * `tabBarOptions()->hidden()` and `$hidesTabBar`), and whether its
     * route is a child route, which hides the bar by default. See
     * {@see isHidden()} for how they combine.
     *
     * The framework calls this for every bar it draws, the layout's and an
     * inline `<native:bottom-nav>` alike.
     */
    public function forScreen(NativeComponent $screen): self
    {
        // tabBarOptions()->hidden() decides either way. `$hidesTabBar`
        // (read through shouldHideTabBar()) can only hide, because false
        // is also what it says when nobody touched it.
        $options = $screen->tabBarOptions();
        $this->hiddenByScreen = match (true) {
            $options?->hidden !== null => $options->hidden,
            $screen->shouldHideTabBar() => true,
            default => null,
        };
        $this->onChildRoute = $screen->isChildScreen();

        return $this;
    }

    /**
     * Whether the bar hides: the screen's own say, else the value set on
     * purpose, else the default from the screen's route. On native chrome
     * this becomes the sentinel's `hide_tab_bar` (the TabView stays, so tab
     * switching keeps working). On the hand-built path the bar is simply
     * not drawn.
     */
    public function isHidden(): bool
    {
        return $this->hiddenByScreen ?? $this->hidden ?? $this->onChildRoute;
    }

    /**
     * iOS 26+ only. When the user scrolls content down, the tab bar
     * shrinks to a pill and the bottom accessory (if any) moves inline
     * with the active tab — Apple's Music / Podcasts pattern. Tapping a
     * tab or scrolling back to the top re-expands the bar.
     */
    public function minimizeOnScroll(bool $value = true): self
    {
        $this->minimizeOnScroll = $value;

        return $this;
    }

    /**
     * Select a plugin-provided native tab-bar presentation for this layout.
     *
     * NativePHP continues to own the root tabs, selection, callbacks, and
     * navigation stacks. The identifier only selects a registered visual
     * presenter; the bounded JSON configuration contains presenter-specific
     * design tokens.
     */
    public function presentation(string $identifier, array $configuration = []): self
    {
        if (
            strlen($identifier) > self::MAX_PRESENTATION_IDENTIFIER_LENGTH
            || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[a-z0-9]+(?:[._-][a-z0-9]+)*)?\z/', $identifier) !== 1
        ) {
            throw new InvalidArgumentException('Tab presentation identifiers must be lowercase, at most 128 bytes, and use letters, numbers, dots, dashes, underscores, and at most one namespace colon.');
        }

        if ($configuration !== [] && array_is_list($configuration)) {
            throw new InvalidArgumentException('Tab presentation configuration must be a JSON object, not a top-level list.');
        }

        try {
            $json = $configuration === [] ? '{}' : json_encode(
                $configuration,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                self::MAX_PRESENTATION_CONFIGURATION_DEPTH
            );
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Tab presentation configuration must contain bounded, JSON-compatible values: '.$exception->getMessage(), previous: $exception);
        }

        self::assertConfigurationValues($configuration);

        if (strlen($json) > self::MAX_PRESENTATION_CONFIGURATION_BYTES) {
            throw new InvalidArgumentException('Tab presentation configuration may not exceed 16384 encoded bytes.');
        }

        $this->presentationIdentifier = $identifier;
        $this->presentationConfiguration = $json;

        return $this;
    }

    private static function assertConfigurationValues(array $configuration): void
    {
        array_walk_recursive($configuration, static function (mixed $value): void {
            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Tab presentation configuration may contain only JSON scalar values, arrays, and null.');
            }

            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('Tab presentation configuration may not contain non-finite numbers.');
            }
        });
    }

    /**
     * Mark the tab that "owns" the current screen as active. The native
     * renderers use that flag to decide which tab's NavigationStack a
     * pushed screen belongs to, so it has to be right for screens that
     * are not a tab's own URL too.
     *
     * The owner is picked in this order:
     *
     *  1. An inline `<native:bottom-nav-item>` the blade marked `active`.
     *     The dev decided, so nothing below runs.
     *  2. The tab whose URL is exactly `$currentUrl`.
     *  3. The tab for the nearest ancestor route. `$ancestorRoutes` is the
     *     screen's chain of parent route patterns, nearest first, as
     *     declared with `Route::native(...)->children(...)`. This is what
     *     lets `/notifications` highlight a Home tab at `/` when its
     *     route is a child of `/`: no URL prefix could tell you that.
     *  4. The tab whose URL is the longest prefix of `$currentUrl` (the
     *     next character must be `/`), so `/chats/123` still falls under
     *     a `/chats` tab without any `children()` call.
     *
     * @param  list<string>  $ancestorRoutes
     */
    public function highlight(string $currentUrl, array $ancestorRoutes = []): self
    {
        // Respect an explicit choice: when this bar was reconstructed from
        // an inline `<native:bottom-nav>` and the blade already marks a tab
        // `active`, the dev decided — don't second-guess with URL matching.
        foreach ($this->prebuiltTabElements as $item) {
            if ($item->isActive()) {
                return $this;
            }
        }

        // Builder tabs AND prebuilt inline items, uniformly.
        $tabs = [...$this->tabs, ...$this->prebuiltTabElements];

        foreach ($tabs as $tab) {
            $tab->setActive(false);
        }

        $owner = $this->tabAtUrl($tabs, $currentUrl)
            ?? $this->tabForNearestAncestor($tabs, $ancestorRoutes)
            ?? $this->tabWithLongestPrefix($tabs, $currentUrl);

        $owner?->setActive(true);

        return $this;
    }

    /**
     * The tab whose URL is exactly `$url`. A screen opened with a query
     * string still belongs to the tab for its path, but a tab whose own
     * URL has a query matches the full URL, and being longer, wins.
     *
     * @param  array<int, Tab|BottomNavItem>  $tabs
     */
    private function tabAtUrl(array $tabs, string $url): Tab|BottomNavItem|null
    {
        $path = NativeRouter::pathOf($url);
        $bestTab = null;
        $bestLen = -1;

        foreach ($tabs as $tab) {
            $tabUrl = $tab->getUrl();
            if ($tabUrl === '') {
                continue;
            }

            if (($tabUrl === $url || $tabUrl === $path) && strlen($tabUrl) > $bestLen) {
                $bestTab = $tab;
                $bestLen = strlen($tabUrl);
            }
        }

        return $bestTab;
    }

    /**
     * Walk the ancestor routes, nearest first, and return the first tab
     * that points at one of them. A tab points at a route when its URL is
     * the route pattern itself, or when the router matches its URL to
     * that pattern (a tab at `/teams/5` for the route `/teams/{team}`).
     *
     * @param  array<int, Tab|BottomNavItem>  $tabs
     * @param  list<string>  $ancestorRoutes
     */
    private function tabForNearestAncestor(array $tabs, array $ancestorRoutes): Tab|BottomNavItem|null
    {
        // Resolved on demand and kept, so the router is only asked about a
        // tab when comparing the plain URL was not enough.
        $matchedRoutes = [];

        foreach ($ancestorRoutes as $pattern) {
            foreach ($tabs as $tab) {
                if ($tab->getUrl() !== '' && NativeRouter::pathOf($tab->getUrl()) === $pattern) {
                    return $tab;
                }
            }

            foreach ($tabs as $index => $tab) {
                if ($tab->getUrl() === '') {
                    continue;
                }

                $matchedRoutes[$index] ??= NativeRouter::patternFor($tab->getUrl()) ?? '';

                if ($matchedRoutes[$index] === $pattern) {
                    return $tab;
                }
            }
        }

        return null;
    }

    /**
     * The tab whose URL is the longest prefix of `$url`'s path, where the
     * prefix ends on a path segment.
     *
     * @param  array<int, Tab|BottomNavItem>  $tabs
     */
    private function tabWithLongestPrefix(array $tabs, string $url): Tab|BottomNavItem|null
    {
        $bestTab = null;
        $bestLen = -1;
        $path = NativeRouter::pathOf($url);

        foreach ($tabs as $tab) {
            $tabUrl = $tab->getUrl();
            if ($tabUrl === '') {
                continue;
            }

            if (str_starts_with($path, $tabUrl.'/') && strlen($tabUrl) > $bestLen) {
                $bestTab = $tab;
                $bestLen = strlen($tabUrl);
            }
        }

        return $bestTab;
    }

    /**
     * Serialize the bar's declarative config as an attrs dict suitable
     * for `NativeRootTabs::applyAttributes()` (camelCase keys: `dark`,
     * `activeColor`, `backgroundColor`, `textColor`, `labelVisibility`,
     * and `hideTabBar` when {@see isHidden()}). Used by the native-chrome
     * rollout path in `NativeComponent::wrapWithNativeChrome()`.
     */
    public function toRootProps(): array
    {
        $attrs = [];
        if ($this->dark) {
            $attrs['dark'] = true;
        }
        if ($this->labelVisibility !== null) {
            $attrs['labelVisibility'] = $this->labelVisibility;
        }
        if ($this->activeColor !== null) {
            $attrs['activeColor'] = $this->activeColor;
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
        if ($this->minimizeOnScroll) {
            $attrs['minimizeOnScroll'] = true;
        }
        if ($this->presentationIdentifier !== null) {
            $attrs['tabPresentationId'] = $this->presentationIdentifier;
            $attrs['tabPresentationConfiguration'] = $this->presentationConfiguration;
        }
        if ($this->isHidden()) {
            $attrs['hideTabBar'] = true;
        }

        return $attrs;
    }

    /** @return Tab[] */
    public function getTabs(): array
    {
        return $this->tabs;
    }

    /**
     * Every tab as a ready-to-attach `bottom_nav_item` element —
     * builder-declared tabs first, then any pre-built elements adopted
     * from an inline `<native:bottom-nav>`. This is what the chrome
     * wrapper attaches to the native root (injecting the search corpus
     * into search-role items along the way), so both sources compose
     * uniformly.
     *
     * @return BottomNavItem[]
     */
    public function tabElements(): array
    {
        return [
            ...array_map(fn (Tab $tab) => $tab->toElement(), $this->tabs),
            ...$this->prebuiltTabElements,
        ];
    }

    public function toElement(): BottomNav
    {
        $nav = BottomNav::make();

        $attrs = [];
        if ($this->dark) {
            $attrs['dark'] = true;
        }
        if ($this->labelVisibility !== null) {
            $attrs['labelVisibility'] = $this->labelVisibility;
        }
        if ($this->activeColor !== null) {
            $attrs['activeColor'] = $this->activeColor;
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
        if ($this->minimizeOnScroll) {
            $attrs['minimizeOnScroll'] = true;
        }
        if ($this->presentationIdentifier !== null) {
            $attrs['tabPresentationId'] = $this->presentationIdentifier;
            $attrs['tabPresentationConfiguration'] = $this->presentationConfiguration;
        }
        // Only a value set on purpose, so fromElement() reads an unset bar
        // back as unset.
        if ($this->hidden !== null) {
            $attrs['hidden'] = $this->hidden;
        }

        $nav->applyAttributes($attrs);

        foreach ($this->tabElements() as $item) {
            $nav->addChild($item);
        }

        return $nav;
    }
}
