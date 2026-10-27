<?php

namespace Native\Mobile\Edge\Elements;

use InvalidArgumentException;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

class Pressable extends Element
{
    protected string $type = 'pressable';

    public static function make(Element ...$children): static
    {
        $el = new static;
        $el->children = $children;

        return $el;
    }

    /**
     * Optional `:menu` attribute attaches a tap-to-open dropdown.
     *
     *   <native:pressable
     *       class="..."
     *       :menu="[
     *           NavAction::make('record')->label('Record')->icon(...)->press('record'),
     *           NavAction::divider(),
     *           NavAction::make('delete')->label('Delete')->destructive()->press('delete'),
     *       ]">
     *       <native:icon name="mic"/>
     *   </native:pressable>
     *
     * Items are emitted as `top_bar_action` children alongside the
     * pressable's content. Renderers filter children by type — content
     * children render normally; `top_bar_action` children become menu
     * rows. The `has_menu` prop signals to the renderer that it should
     * wrap content in a Menu (iOS) / DropdownMenu (Android) and treat
     * tap as menu-open instead of firing the `@tap` callback.
     *
     * Optional `:context-menu` attribute takes the same items but opens
     * them on a long press, and leaves the tap to `@press`, like a row
     * in a list of conversations: tap opens it, press and hold for Pin,
     * Mute, Delete.
     *
     *   <native:pressable @press="open('42')" :context-menu="[
     *       NavAction::make('pin')->label('Pin')->icon(...)->press('pin(\'42\')'),
     *       NavAction::divider(),
     *       NavAction::make('delete')->label('Delete')->destructive()->press('delete(\'42\')'),
     *   ]">
     *       ...
     *   </native:pressable>
     *
     * It's the platform's own context menu: SwiftUI's `.contextMenu` on
     * iOS, which lifts the element and plays the haptic, and on Android
     * a long click that opens the same dropdown `:menu` does, where the
     * finger is, with the long-press haptic. The items ride as
     * `top_bar_action` children, as with `:menu`, and `has_context_menu`
     * tells the renderer to attach them to the long press. Don't
     * combine it with `@longPress`, which wants the same gesture. A
     * pressable takes one menu or the other.
     *
     * Items can be `NavAction` instances or pre-built `TopBarAction`
     * elements (the latter for cases where the dev pre-resolved them).
     */
    public function applyAttributes(array $attrs): void
    {
        $menu = $this->menuItems($attrs['menu'] ?? null);
        $contextMenu = $this->menuItems($attrs['context-menu'] ?? $attrs['contextMenu'] ?? null);

        if ($menu !== [] && $contextMenu !== []) {
            throw new InvalidArgumentException('A pressable takes a :menu (opened by a tap) or a :context-menu (opened by a long press), not both.');
        }

        foreach ([...$menu, ...$contextMenu] as $item) {
            $this->addChild($item);
        }

        if ($menu !== []) {
            $this->setProp('has_menu', true);
        }

        if ($contextMenu !== []) {
            $this->setProp('has_context_menu', true);
        }
    }

    /**
     * @return list<Element>
     */
    private function menuItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $elements = [];

        foreach ($items as $item) {
            if ($item instanceof NavAction) {
                $elements[] = $item->toElement();
            } elseif ($item instanceof Element) {
                $elements[] = $item;
            }
        }

        return $elements;
    }
}
