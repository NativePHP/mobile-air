<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Nested fixture that emits a component event to its screen, and
 * listens for a marked event of its own. Such an event stops
 * at the screen, so the count it shows stays at zero.
 */
class ListenersChild extends NativeComponent
{
    public int $heard = 0;

    public function pick(int $id): void
    {
        $this->emit('item-picked', id: $id);
    }

    #[On(OrderShipped::class)]
    public function shipped(): void
    {
        $this->heard++;
    }

    public function render(): Element
    {
        return Column::make(
            Text::make("Child heard {$this->heard}"),
            Pressable::make(Text::make('Pick five'))->ref('pick-5')->onPress('pick(5)'),
            Pressable::make(Text::make('Pick six'))->ref('pick-6')->onPress('pick(6)'),
        );
    }
}
