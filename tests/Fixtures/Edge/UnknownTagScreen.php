<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen fixture using a tag that is neither an element nor a component. */
class UnknownTagScreen extends NativeComponent
{
    public function render(): View
    {
        return view('unknown-tag-screen');
    }
}
