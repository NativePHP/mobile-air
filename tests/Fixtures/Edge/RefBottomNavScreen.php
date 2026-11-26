<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose inline `<native:bottom-nav>` names one item with `ref`. */
class RefBottomNavScreen extends NativeComponent
{
    public function render(): View
    {
        return view('ref-bottom-nav-screen');
    }
}
