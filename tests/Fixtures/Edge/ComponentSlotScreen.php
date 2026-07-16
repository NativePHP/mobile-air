<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Blade-rendering fixture for the render-count tests: an @include runs
 * inside an open <x-*> component, so the native render must hold the
 * View factory's render count above zero or the include's completion
 * flushes the component slot storage mid-render.
 */
class ComponentSlotScreen extends NativeComponent
{
    public function render(): View
    {
        return view('component-slot-screen');
    }
}
