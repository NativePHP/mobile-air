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

    private ?string $image = null;

    private ?string $event = null;

    private ?string $press = null;

    private bool $destructive = false;

    private bool $disabled = false;

    private ?string $subtitle = null;

    private bool $checked = false;

    /**
     * Marks this action as a visual divider rather than a tappable row.
     * Use [divider] to construct one — when set, the renderer emits a
     * separator line in place of a `Button` / `DropdownMenuItem`. All
     * other props (icon, label, press, …) are ignored on a divider.
     */
    private bool $isDivider = false;

    /**
     * Marks this action as a group of menu items rather than a row of its
     * own. Use [section] to construct one. Only the title (sent as
     * `label`) and the items are emitted.
     */
    private bool $isSection = false;

    /**
     * Marks this action as the compact header row of a menu. Use [row] to
     * construct one. Only the items are emitted.
     */
    private bool $isRow = false;

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

    /**
     * Group menu items under an optional heading, for use inside `items()`.
     *
     *   NavAction::make('more')->items([
     *       NavAction::section('Sort by', [
     *           NavAction::make('sort_date')->label('Date')->checked()->press('sortBy(\'date\')'),
     *           NavAction::make('sort_title')->label('Title')->press('sortBy(\'title\')'),
     *       ]),
     *       NavAction::section(items: [
     *           NavAction::make('delete')->label('Delete')->destructive()->press('delete'),
     *       ]),
     *   ])
     *
     * iOS draws an inline `UIMenu` section with the title as its heading
     * and a separator between sections. Android draws the title as a
     * small heading, with a divider between the section and the items
     * around it. The id is a sentinel, and a section ignores icon(),
     * press() and the other item methods.
     *
     * @param  NavAction[]  $items
     */
    public static function section(?string $title = null, array $items = []): self
    {
        $section = new self('section');
        $section->isSection = true;
        $section->label = $title;
        $section->items = $items;

        return $section;
    }

    /**
     * A compact row of buttons across the top of a menu, each an icon
     * over a short label, like Scan, Pin and Lock at the top of the Notes
     * menu. For use inside `items()`.
     *
     *   NavAction::row([
     *       NavAction::make('scan')->label('Scan')->icon('doc.viewfinder')->press('scan'),
     *       NavAction::make('pin')->label('Pin')->icon('pin')->press('pin'),
     *       NavAction::make('lock')->label('Lock')->icon('lock')->press('lock'),
     *   ])
     *
     * iOS renders it as a medium-size inline `UIMenu` (icon above label).
     * Android renders a row of icon-and-label buttons at the top of the
     * dropdown. Keep it to a few items with short labels. Like a section,
     * its id is a sentinel and only its items are sent.
     *
     * @param  NavAction[]  $items
     */
    public static function row(array $items): self
    {
        $row = new self('row');
        $row->isRow = true;
        $row->items = $items;

        return $row;
    }

    /**
     * Show a remote image (e.g. the signed-in user's avatar) in a small
     * circle instead of the icon. The icon still shows while the image
     * loads and if it fails, so set one too. Empty strings are ignored, so
     * `->image($user->avatarUrl ?? '')` falls back to the icon cleanly.
     */
    public function image(?string $url): self
    {
        $this->image = ($url === null || trim($url) === '') ? null : $url;

        return $this;
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
     * Grey the action out and ignore taps — for actions that are sometimes
     * unavailable (undo with nothing to undo, save with no changes).
     * Attributes are reactive, so `->disabled(! $this->canUndo)` updates
     * live as the screen's state changes.
     */
    public function disabled(bool $value = true): self
    {
        $this->disabled = $value;

        return $this;
    }

    /**
     * A second line of smaller text under a menu item's label, for a hint
     * or the current value ("Sorted by date").
     *
     *   NavAction::make('sort')->label('Sort')->subtitle('Date')->items([...]);
     *
     * iOS shows it as the menu element's `subtitle`. Android shows it as
     * a second line in the dropdown item.
     */
    public function subtitle(string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    /**
     * Show a checkmark beside a menu item, for the selected option in a
     * group or a setting that is on. Attributes are reactive, so
     * `->checked($this->sort === 'date')` follows the screen's state.
     *
     *   NavAction::make('show_done')->label('Show completed')
     *       ->checked($this->showDone)->press('toggleDone');
     *
     * iOS draws the menu's own checkmark (`UIMenuElement.State.on`).
     * Android draws a check icon on the dropdown item.
     */
    public function checked(bool $value = true): self
    {
        $this->checked = $value;

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
     * Give an item inside a menu its own `items()` and it becomes a
     * submenu. iOS opens it in place as a nested `UIMenu` with a chevron.
     * Android drills into it, with a back row at the top to return.
     *
     *   NavAction::make('sort')->label('Sort by')->icon('arrow.up.arrow.down')->items([
     *       NavAction::make('sort_date')->label('Date')->checked()->press('sortBy(\'date\')'),
     *       NavAction::make('sort_title')->label('Title')->press('sortBy(\'title\')'),
     *   ]),
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

        // Section and row sentinels are containers: the renderer only
        // needs the flag, a section's heading and the children.
        if ($this->isSection || $this->isRow) {
            $attrs[$this->isSection ? 'section' : 'row'] = true;
            if ($this->isSection && $this->label !== null) {
                $attrs['label'] = $this->label;
            }
            $action->applyAttributes($attrs);

            foreach ($this->items as $item) {
                $action->addChild($item->toElement());
            }

            return $action;
        }

        if (($icon = $this->resolvedIcon()) !== null) {
            $attrs['icon'] = $icon;
            if (($variant = $this->resolvedMaterialVariant()) !== null) {
                $attrs['material_variant'] = $variant;
            }
        }
        if ($this->label !== null) {
            $attrs['label'] = $this->label;
        }
        if ($this->url !== null) {
            $attrs['url'] = $this->url;
        }
        if ($this->image !== null) {
            $attrs['image'] = $this->image;
        }
        if ($this->event !== null) {
            $attrs['event'] = $this->event;
        }
        if ($this->destructive) {
            $attrs['destructive'] = true;
        }
        if ($this->disabled) {
            $attrs['disabled'] = true;
        }
        if ($this->subtitle !== null) {
            $attrs['subtitle'] = $this->subtitle;
        }
        if ($this->checked) {
            $attrs['checked'] = true;
        }

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
