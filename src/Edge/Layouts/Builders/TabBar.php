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

    private ?string $labelVisibility = null;

    private bool $dark = false;

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

    public function toElement(): BottomNav
    {
        $nav = BottomNav::make();

        $attrs = [];
        if ($this->dark)                       $attrs['dark']            = true;
        if ($this->labelVisibility !== null)   $attrs['labelVisibility'] = $this->labelVisibility;
        if ($this->activeColor !== null)       $attrs['activeColor']     = $this->activeColor;

        $nav->applyAttributes($attrs);

        foreach ($this->tabs as $tab) {
            $nav->addChild($tab->toElement());
        }

        return $nav;
    }
}
