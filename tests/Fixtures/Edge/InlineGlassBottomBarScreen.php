<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose blade declares an inline `<native:bottom-bar>` carrying `glass`. */
class InlineGlassBottomBarScreen extends NativeComponent
{
    public function render(): View
    {
        return view('inline-glass-bottom-bar-screen');
    }
}
