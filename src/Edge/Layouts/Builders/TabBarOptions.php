<?php

namespace Native\Mobile\Edge\Layouts\Builders;

/**
 * Optional declarative override for a screen — parallel to [NavBarOptions]
 * but for the bottom tab strip.
 *
 * Screens can implement `tabBarOptions(): ?TabBarOptions` to influence
 * what the layout's tab bar does on this specific screen. Non-null
 * fields override the layout's defaults; null fields fall through.
 *
 *   public function tabBarOptions(): ?TabBarOptions
 *   {
 *       return TabBarOptions::make()
 *           ->hidden()                          // pushed-detail screens
 *           ->highlight('chats');               // force a tab as "active"
 *   }
 *
 * For the common "hide the tab bar on this detail screen" case, the
 * shorter `protected bool $hidesTabBar = true;` property on the screen
 * is equivalent to `TabBarOptions::make()->hidden()`. Use either; if
 * both are set, the explicit builder wins.
 *
 * Per-screen tab content overrides (insert/remove tabs) are intentionally
 * out of scope — that complicates merge semantics and creates UX where
 * tabs change identity per screen. Define your tabs once at the layout
 * level via [TabBar].
 */
class TabBarOptions
{
    public ?bool $hidden = null;

    /** Force a specific tab id as the "active" one regardless of route match. */
    public ?string $highlight = null;

    public ?string $activeColor = null;

    public ?string $backgroundColor = null;

    public static function make(): self
    {
        return new self;
    }

    /** Hide the tab bar on this screen. Detail / pushed-content pattern. */
    public function hidden(bool $hidden = true): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Force a specific tab id as the visually "active" one. Useful when a
     * screen lives at a URI that doesn't match any tab's URL but is
     * conceptually "inside" one of the tabs (e.g. a search-results
     * screen reached from the Search tab).
     */
    public function highlight(string $tabId): self
    {
        $this->highlight = $tabId;

        return $this;
    }

    public function activeColor(string $color): self
    {
        $this->activeColor = $color;

        return $this;
    }

    public function backgroundColor(string $color): self
    {
        $this->backgroundColor = $color;

        return $this;
    }

    public function isHidden(): bool
    {
        return $this->hidden === true;
    }
}
