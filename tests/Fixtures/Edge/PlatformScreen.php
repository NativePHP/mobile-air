<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class PlatformScreen extends NativeComponent
{
    public function render(): View
    {
        return view('platform-screen');
    }
}
