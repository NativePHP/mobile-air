<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose inline `<native:bottom-bar>` asks for the soft scroll edge effect, over glass for older iOS. */
class InlineScrollEdgeBottomBarScreen extends NativeComponent
{
    public function render(): View
    {
        return view('inline-scroll-edge-bottom-bar-screen');
    }
}
