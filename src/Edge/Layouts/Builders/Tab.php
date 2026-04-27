<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Native\Mobile\Edge\Elements\BottomNavItem;

/**
 * Fluent builder for a single bottom-tab-bar item.
 *
 * BottomNavItem auto-wires its `url` attribute to a navigation event,
 * so simply setting the url is enough to make the tab navigate when
 * tapped. No press handler needed.
 *
 * Usage:
 *   Tab::link('Home', '/', icon: 'home')
 *   Tab::link('Profile', '/profile', icon: 'person')->badge('3')
 */
class Tab
{
    private string $id;

    private string $label;

    private string $url;

    private ?string $icon = null;

    private ?string $badge = null;

    private ?string $badgeColor = null;

    private bool $news = false;

    private bool $active = false;

    private function __construct(string $id, string $label, string $url)
    {
        $this->id = $id;
        $this->label = $label;
        $this->url = $url;
    }

    /**
     * Most common form: a label, the url to navigate to, and an icon.
     * The id defaults to the label slugified.
     */
    public static function link(string $label, string $url, ?string $icon = null): self
    {
        $tab = new self(
            id: strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)),
            label: $label,
            url: $url,
        );
        if ($icon !== null) {
            $tab->icon = $icon;
        }

        return $tab;
    }

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function badge(string $badge, ?string $color = null): self
    {
        $this->badge = $badge;
        $this->badgeColor = $color;

        return $this;
    }

    public function news(bool $news = true): self
    {
        $this->news = $news;

        return $this;
    }

    public function active(bool $active = true): self
    {
        $this->active = $active;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function toElement(): BottomNavItem
    {
        $item = BottomNavItem::make();

        $attrs = [
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->url,
            'active' => $this->active,
        ];
        if ($this->icon !== null)        $attrs['icon']       = $this->icon;
        if ($this->badge !== null)       $attrs['badge']      = $this->badge;
        if ($this->badgeColor !== null)  $attrs['badgeColor'] = $this->badgeColor;
        if ($this->news)                 $attrs['news']       = true;

        $item->applyAttributes($attrs);

        return $item;
    }
}
