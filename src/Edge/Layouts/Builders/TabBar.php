<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Elements\BottomNavItem;

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
 */
class TabBar
{
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
     * Mark the tab that "owns" the current URL as active. Uses
     * longest-prefix matching so a pushed sub-route (e.g.
     * `/syncup-native/chat/123` under a `/syncup-native` Chats tab)
     * still highlights the right tab even when it's not the tab's
     * exact URL — letting native chrome route the pushed level
     * through that tab's NavigationStack instead of replacing the
     * whole layout.
     *
     * Tab URLs that are an exact match OR a prefix of `currentUrl`
     * (where the next char is `/`) are candidates; the longest
     * matching tab wins. This is a superset of the previous
     * exact-match behavior.
     */
    public function highlight(string $currentUrl): self
    {
        // Respect an explicit choice: when this bar was reconstructed from
        // an inline `<native:bottom-nav>` and the blade already marks a tab
        // `active`, the dev decided — don't second-guess with URL matching.
        foreach ($this->prebuiltTabElements as $item) {
            if ($item->isActive()) {
                return $this;
            }
        }

        // Longest-prefix match across builder tabs AND prebuilt inline
        // items, uniformly.
        $bestTab = null;
        $bestLen = -1;

        foreach ([...$this->tabs, ...$this->prebuiltTabElements] as $tab) {
            $tab->setActive(false);

            $tabUrl = $tab->getUrl();
            if ($tabUrl === '') {
                continue;
            }

            $isExact = $tabUrl === $currentUrl;
            $isPrefix = str_starts_with($currentUrl, $tabUrl.'/');

            if (($isExact || $isPrefix) && strlen($tabUrl) > $bestLen) {
                $bestTab = $tab;
                $bestLen = strlen($tabUrl);
            }
        }

        if ($bestTab !== null) {
            $bestTab->setActive(true);
        }

        return $this;
    }

    /**
     * Serialize the bar's declarative config as an attrs dict suitable
     * for `NativeRootTabs::applyAttributes()` (camelCase keys: `dark`,
     * `activeColor`, `backgroundColor`, `textColor`, `labelVisibility`).
     * Used by the native-chrome rollout path in
     * `NativeComponent::wrapWithNativeChrome()`.
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

        $nav->applyAttributes($attrs);

        foreach ($this->tabElements() as $item) {
            $nav->addChild($item);
        }

        return $nav;
    }
}
