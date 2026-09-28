<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class NestedPollAndOnScreen extends NativeComponent
{
    public function render(): View
    {
        return view('nested-poll-and-on-screen');
    }
}
