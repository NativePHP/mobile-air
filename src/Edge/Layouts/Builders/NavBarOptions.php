<?php

namespace Native\Mobile\Edge\Layouts\Builders;

/**
 * Optional declarative override for a screen.
 *
 * Screens can implement navigationOptions(): ?NavBarOptions to influence
 * what their layout's NavBar shows. Non-null fields override the layout's
 * defaults; null fields fall through.
 *
 *   public function navigationOptions(): ?NavBarOptions
 *   {
 *       return NavBarOptions::make()
 *           ->title("Item #{$this->id}")
 *           ->action(NavAction::make('save')->icon('save')->press('save'));
 *   }
 */
class NavBarOptions
{
    public ?string $title = null;

    public ?string $subtitle = null;

    public ?bool $back = null;

    public ?string $backgroundColor = null;

    public ?string $textColor = null;

    public ?int $elevation = null;

    /** Title display mode — `large` | `inline` | `automatic`. See NavBar::displayMode(). */
    public ?string $displayMode = null;

    /** @var NavAction[] */
    public array $actions = [];

    /**
     * Inline search bar config — Apple HIG / Expo Router pattern.
     *
     * When set, the chrome's NavBar gets a search field attached to its
     * toolbar (iOS: .searchable; Android: M3 SearchBar in the top app
     * bar). Query text changes fire `onQueryMethod` on the screen.
     *
     * Set via [searchBar]; null when no search is configured.
     *
     * @var array{placeholder?: string, onQuery?: string, debounceMs?: int}|null
     */
    public ?array $searchBar = null;

    public static function make(): self
    {
        return new self;
    }

    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
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

    public function elevation(int $px): self
    {
        $this->elevation = $px;

        return $this;
    }

    public function displayMode(string $mode): self
    {
        $this->displayMode = $mode;

        return $this;
    }

    public function action(NavAction $action): self
    {
        $this->actions[] = $action;

        return $this;
    }

    /**
     * Attach an inline search field to this screen's nav bar.
     *
     *   public function navigationOptions(): ?NavBarOptions
     *   {
     *       return NavBarOptions::make()
     *           ->searchBar(
     *               placeholder: 'Search…',
     *               onQuery: 'updateQuery',
     *               debounceMs: 300,
     *           );
     *   }
     *
     *   public function updateQuery(string $text): void { … }
     *
     * iOS: maps to `.searchable(text: …, prompt: placeholder)` on the
     * destination view inside the NavigationStack — gets the system
     * UISearchController treatment for free (Liquid Glass on iOS 26+,
     * scroll-collapse, keyboard handling).
     *
     * Android: maps to an M3 search field placed at the top of the
     * screen content (above the body, below the toolbar) — closest
     * idiomatic match.
     *
     * `debounceMs` (default 300) coalesces rapid keystrokes so the
     * onQuery callback isn't fired on every character. Set 0 for
     * live-on-every-keystroke.
     */
    public function searchBar(
        string $placeholder = '',
        ?string $onQuery = null,
        int $debounceMs = 300,
    ): self {
        $this->searchBar = [
            'placeholder' => $placeholder,
            'onQuery'     => $onQuery,
            'debounceMs'  => $debounceMs,
        ];

        return $this;
    }
}
