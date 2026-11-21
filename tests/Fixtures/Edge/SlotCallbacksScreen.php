<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen with tap handlers on both sides of a Blade component: one on an
 * element written in the component's slot, one on an element in the
 * component's own view. Both must dispatch to this screen. The slot also
 * mounts a child component, whose handlers stay its own.
 */
class SlotCallbacksScreen extends NativeComponent
{
    public int $innerTaps = 0;

    public int $wrapperTaps = 0;

    public function innerTapped(): void
    {
        $this->innerTaps++;
    }

    public function wrapperTapped(): void
    {
        $this->wrapperTaps++;
    }

    public function render(): View
    {
        return view('slot-callbacks-screen');
    }
}
