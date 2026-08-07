<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose blade declares a `custom` top bar (drawn in-tree). */
class CustomTopBarScreen extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Layout Title Should Lose';
    }

    public function render(): View
    {
        return view('custom-top-bar-screen');
    }
}
