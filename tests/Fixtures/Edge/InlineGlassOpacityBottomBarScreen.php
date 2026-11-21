<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose inline glass `<native:bottom-bar>` sets `glass-opacity` from state. */
class InlineGlassOpacityBottomBarScreen extends NativeComponent
{
    public float|string|null $glassOpacity = 0.6;

    public function render(): View
    {
        return view('inline-glass-opacity-bottom-bar-screen', ['glassOpacity' => $this->glassOpacity]);
    }
}
