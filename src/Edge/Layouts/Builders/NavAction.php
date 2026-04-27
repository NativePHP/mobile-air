<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Native\Mobile\Edge\Elements\TopBarAction;

/**
 * Fluent builder for a top-bar action (right-side icon buttons).
 *
 * Usage:
 *   NavAction::make('save')->icon('save')->press('save')
 */
class NavAction
{
    private string $id;

    private ?string $icon = null;

    private ?string $label = null;

    private ?string $url = null;

    private ?string $event = null;

    private ?string $press = null;

    private function __construct(string $id)
    {
        $this->id = $id;
    }

    public static function make(string $id): self
    {
        return new self($id);
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function url(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function event(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    public function press(string $method): self
    {
        $this->press = $method;

        return $this;
    }

    /**
     * Build the underlying Element.
     */
    public function toElement(): TopBarAction
    {
        $action = TopBarAction::make();

        $attrs = ['id' => $this->id];
        if ($this->icon !== null)  $attrs['icon']  = $this->icon;
        if ($this->label !== null) $attrs['label'] = $this->label;
        if ($this->url !== null)   $attrs['url']   = $this->url;
        if ($this->event !== null) $attrs['event'] = $this->event;

        $action->applyAttributes($attrs);

        if ($this->press !== null) {
            $action->onPress($this->press);
        }

        return $action;
    }
}
