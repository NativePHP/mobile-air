<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Concerns\ValidatesInput;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\GestureArea;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Fixture for `@dragEnd` going through the same interaction path as a
 * tap: the callable guard, validation errors and the event flush.
 */
class DragEndInteractionScreen extends NativeComponent
{
    use ValidatesInput;

    public bool $hiddenRan = false;

    public array $events = [];

    public function rejectDrag(float $x, float $y): void
    {
        throw ValidationException::withMessages(['position' => 'Dropped out of bounds.']);
    }

    public function dropped(float $x, float $y): void
    {
        $this->dispatch('dropped', x: $x);
    }

    #[On('dropped')]
    public function recordDrop(float $x): void
    {
        $this->events[] = "dropped:{$x}";
    }

    protected function hidden(float $x, float $y): void
    {
        $this->hiddenRan = true;
    }

    public function render(): Element|View
    {
        return Column::make(
            GestureArea::make()->onDragEnd('hidden')->ref('hidden-area'),
            GestureArea::make()->onDragEnd('rejectDrag')->ref('reject-area'),
            GestureArea::make()->onDragEnd('dropped')->ref('drop-area'),
            Text::make('Events: '.implode(',', $this->events)),
        );
    }
}
