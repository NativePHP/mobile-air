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

    /** @var NavAction[] */
    public array $actions = [];

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

    public function action(NavAction $action): self
    {
        $this->actions[] = $action;

        return $this;
    }
}
