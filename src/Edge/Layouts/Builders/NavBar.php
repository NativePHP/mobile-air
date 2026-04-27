<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Native\Mobile\Edge\Elements\TopBar;

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

    private bool $back = false;

    private ?string $backgroundColor = null;

    private ?string $textColor = null;

    private ?int $elevation = null;

    /** @var NavAction[] */
    private array $actions = [];

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

    /**
     * Apply a NavBarOptions struct from a screen's navigationOptions().
     * Non-null fields on $opts override our values.
     */
    public function mergeOptions(?NavBarOptions $opts): self
    {
        if ($opts === null) {
            return $this;
        }
        if ($opts->title !== null)           $this->title = $opts->title;
        if ($opts->subtitle !== null)        $this->subtitle = $opts->subtitle;
        if ($opts->back !== null)            $this->back = $opts->back;
        if ($opts->backgroundColor !== null) $this->backgroundColor = $opts->backgroundColor;
        if ($opts->textColor !== null)       $this->textColor = $opts->textColor;
        if ($opts->elevation !== null)       $this->elevation = $opts->elevation;
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
        if (isset($state['title']))            $this->title = $state['title'];
        if (isset($state['subtitle']))         $this->subtitle = $state['subtitle'];
        if (isset($state['back']))             $this->back = (bool) $state['back'];
        if (isset($state['backgroundColor']))  $this->backgroundColor = $state['backgroundColor'];
        if (isset($state['textColor']))        $this->textColor = $state['textColor'];
        if (isset($state['elevation']))        $this->elevation = (int) $state['elevation'];

        return $this;
    }

    public function toElement(): TopBar
    {
        $bar = TopBar::make();

        $attrs = ['showNavigationIcon' => $this->back];
        if ($this->title !== null)           $attrs['title']           = $this->title;
        if ($this->subtitle !== null)        $attrs['subtitle']        = $this->subtitle;
        if ($this->backgroundColor !== null) $attrs['backgroundColor'] = $this->backgroundColor;
        if ($this->textColor !== null)       $attrs['textColor']       = $this->textColor;
        if ($this->elevation !== null)       $attrs['elevation']       = $this->elevation;

        $bar->applyAttributes($attrs);

        foreach ($this->actions as $action) {
            $bar->addChild($action->toElement());
        }

        return $bar;
    }
}
