<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose inline `<native:bottom-bar>` carries glass with an opacity suffix. */
class InlineFadedGlassBottomBarScreen extends NativeComponent
{
    public function render(): View
    {
        return view('inline-faded-glass-bottom-bar-screen');
    }
}
