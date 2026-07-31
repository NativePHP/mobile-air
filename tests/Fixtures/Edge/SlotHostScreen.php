<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen fixture that (illegally) gives a component tag slot content. */
class SlotHostScreen extends NativeComponent
{
    public function render(): View
    {
        return view('slot-host-screen');
    }
}
