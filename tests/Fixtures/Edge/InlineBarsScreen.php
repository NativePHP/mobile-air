<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen whose blade declares both bars inline and leaves the back button
 * and the active tab unset, so the framework's defaults show through.
 */
class InlineBarsScreen extends NativeComponent
{
    public function render(): View
    {
        return view('inline-bars-screen');
    }
}
