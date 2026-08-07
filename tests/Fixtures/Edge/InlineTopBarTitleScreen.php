<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose inline `<native:top-bar>` carries a `<native:top-bar-title>` lockup. */
class InlineTopBarTitleScreen extends NativeComponent
{
    public int $brandTaps = 0;

    public function brandTapped(): void
    {
        $this->brandTaps++;
    }

    public function render(): View
    {
        return view('inline-top-bar-title-screen');
    }
}
