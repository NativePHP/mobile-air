<?php

namespace Native\Mobile\Edge\Elements;

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
     * tap as menu-open instead of firing the `@press` callback.
     *
     * Items can be `NavAction` instances or pre-built `TopBarAction`
     * elements (the latter for cases where the dev pre-resolved them).
     */
    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['menu']) && is_array($attrs['menu']) && ! empty($attrs['menu'])) {
            foreach ($attrs['menu'] as $item) {
                if ($item instanceof NavAction) {
                    $this->addChild($item->toElement());
                } elseif ($item instanceof Element) {
                    $this->addChild($item);
                }
            }
            $this->setProp('has_menu', true);
        }
    }
}
