<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose blade declares an inline `<native:bottom-nav>` (non-custom). */
class InlineBottomNavScreen extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Bottom Screen';
    }

    public function render(): View
    {
        return view('inline-bottom-nav-screen');
    }
}
