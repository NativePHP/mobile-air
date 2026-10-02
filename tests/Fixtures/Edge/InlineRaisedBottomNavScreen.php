<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose inline `<native:bottom-nav>` raises an item through attributes. */
class InlineRaisedBottomNavScreen extends NativeComponent
{
    public bool $canCreate = true;

    public int $creates = 0;

    public function create(): void
    {
        $this->creates++;
    }

    public function render(): View
    {
        return view('inline-raised-bottom-nav-screen');
    }
}
