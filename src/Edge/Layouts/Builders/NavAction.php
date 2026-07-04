<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use Native\Mobile\Concerns\HasPlatformIcon;
use Native\Mobile\Edge\Elements\TopBarAction;

/**
 * Fluent builder for a top-bar action (right-side icon buttons).
 *
 * Usage:
 *   NavAction::make('save')->icon('save')->press('save');
 *
 *   NavAction::make('mute')
 *       ->icon(ios: Ios::BellSlash, android: Android::NotificationsOff)
 *       ->press('mute');
 */
class NavAction
{
    use HasPlatformIcon;

    private string $id;

    private ?string $label = null;

    private ?string $a11yLabel = null;

    private ?string $url = null;

    private ?string $event = null;

    private ?string $press = null;

    private bool $destructive = false;

    /**
     * Marks this action as a visual divider rather than a tappable row.
     * Use [divider] to construct one — when set, the renderer emits a
     * separator line in place of a `Button` / `DropdownMenuItem`. All
     * other props (icon, label, press, …) are ignored on a divider.
     */
    private bool $isDivider = false;

    /** @var NavAction[] — sub-items rendered as a SwiftUI `Menu` when set. */
    private array $items = [];

    private function __construct(string $id)
    {
        $this->id = $id;
    }

    public static function make(string $id): self
    {
        return new self($id);
    }

    /**
     * Visual divider for use inside `items()`.
     *
     *   NavAction::make('more')->items([
     *       NavAction::make('mute')->...,
     *       NavAction::divider(),
     *       NavAction::make('delete')->destructive()->...,
     *   ])
     *
     * Renders as `Divider()` (iOS Menu) / `HorizontalDivider()` (Compose
     * DropdownMenu). The id is just a sentinel — never seen by users.
     */
    public static function divider(): self
    {
        $div = new self('divider');
        $div->isDivider = true;

        return $div;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Screen-reader label for icon-only actions (wire key `a11y_label`).
     * Icon-only top-bar buttons are otherwise unlabeled for VoiceOver /
     * TalkBack — set this whenever no visible `label()` is given.
     */
    public function a11yLabel(string $label): self
    {
        $this->a11yLabel = $label;

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
     * Mark a menu item as destructive — iOS renders the label in red
     * via `Button(role: .destructive)`. Use for "Delete", "Block",
     * "Clear history" style entries.
     */
    public function destructive(bool $value = true): self
    {
        $this->destructive = $value;

        return $this;
    }

    /**
     * Attach sub-items so this action renders as a pull-down menu instead
     * of a plain button. Tapping the bar's icon reveals a SwiftUI `Menu`
     * (iOS) / Compose `DropdownMenu` (Android) of these items. Each
     * sub-item is itself a `NavAction` — so it carries `label()`, `icon()`,
     * `press()` like a top-level action.
     *
     *   NavAction::make('more')
     *       ->icon('ellipsis')
     *       ->items([
     *           NavAction::make('mute')->label('Mute')->icon('bell.slash')->press('mute'),
     *           NavAction::make('block')->label('Block')->icon('hand.raised')->press('block'),
     *       ]);
     *
     * @param  NavAction[]  $items
     */
    public function items(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Build the underlying Element.
     */
    public function toElement(): TopBarAction
    {
        $action = TopBarAction::make();

        $attrs = ['id' => $this->id];

        // Divider sentinel — strip everything else so the renderer just
        // sees `divider: true` and emits a separator line, no row.
        if ($this->isDivider) {
            $attrs['divider'] = true;
            $action->applyAttributes($attrs);

            return $action;
        }

        if (($icon = $this->resolvedIcon()) !== null) {
            $attrs['icon'] = $icon;
            if (($variant = $this->resolvedMaterialVariant()) !== null) {
                $attrs['material_variant'] = $variant;
            }
        }
        if ($this->label !== null) $attrs['label']       = $this->label;
        if ($this->url !== null)   $attrs['url']         = $this->url;
        if ($this->event !== null) $attrs['event']       = $this->event;
        if ($this->destructive)    $attrs['destructive'] = true;

        $action->applyAttributes($attrs);

        if ($this->a11yLabel !== null) {
            $action->a11yLabel($this->a11yLabel);
        }

        if ($this->press !== null) {
            $action->onPress($this->press);
        }

        // Sub-items become child TopBarAction elements. The renderer
        // reads them and substitutes a `Menu` for the trailing button
        // when this list is non-empty.
        foreach ($this->items as $item) {
            $action->addChild($item->toElement());
        }

        return $action;
    }
}
