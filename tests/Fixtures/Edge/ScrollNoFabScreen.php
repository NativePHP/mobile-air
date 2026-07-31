<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Same screen as ScrollFabScreen minus the fab — the known-good tree. */
class ScrollNoFabScreen extends NativeComponent
{
    public array $tasks = ['One', 'Two', 'Three'];

    public function render(): View
    {
        return view('scroll-nofab-screen');
    }
}
