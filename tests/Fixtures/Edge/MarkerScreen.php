<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Blade-rendering fixture for the compiled-view marker tests: the view
 * (and its @include'd partial) must recompile natively even when a web
 * render compiled them first.
 */
class MarkerScreen extends NativeComponent
{
    public function render(): View
    {
        return view('marker-screen');
    }
}
