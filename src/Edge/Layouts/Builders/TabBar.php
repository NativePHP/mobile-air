<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Native\Mobile\Edge\Elements\BottomNav;

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

    private ?string $activeColor = null;

    private ?string $textColor = null;

    private ?string $backgroundColor = null;

    private ?string $labelVisibility = null;

    private bool $dark = false;

    private bool $minimizeOnScroll = false;

    public static function make(): self
    {
        return new self;
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
     */
    public function textColor(string $color): self
    {
        $this->textColor = $color;

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
     * Mark the tab whose url matches as active. Called by the framework
     * once the current route is known.
     */
    public function highlight(string $currentUrl): self
    {
        foreach ($this->tabs as $tab) {
            $tab->setActive($tab->getUrl() === $currentUrl);
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
        if ($this->dark)                       $attrs['dark']             = true;
        if ($this->labelVisibility !== null)   $attrs['labelVisibility']  = $this->labelVisibility;
        if ($this->activeColor !== null)       $attrs['activeColor']      = $this->activeColor;
        if ($this->backgroundColor !== null)   $attrs['backgroundColor']  = $this->backgroundColor;
        if ($this->textColor !== null)         $attrs['textColor']        = $this->textColor;
        if ($this->minimizeOnScroll)           $attrs['minimizeOnScroll'] = true;
        return $attrs;
    }

    /** @return Tab[] */
    public function getTabs(): array
    {
        return $this->tabs;
    }

    public function toElement(): BottomNav
    {
        $nav = BottomNav::make();

        $attrs = [];
        if ($this->dark)                       $attrs['dark']            = true;
        if ($this->labelVisibility !== null)   $attrs['labelVisibility'] = $this->labelVisibility;
        if ($this->activeColor !== null)       $attrs['activeColor']     = $this->activeColor;
        if ($this->backgroundColor !== null)   $attrs['backgroundColor'] = $this->backgroundColor;
        if ($this->textColor !== null)         $attrs['textColor']       = $this->textColor;

        $nav->applyAttributes($attrs);

        foreach ($this->tabs as $tab) {
            $nav->addChild($tab->toElement());
        }

        return $nav;
    }
}
