<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class NavigateRouteScreen extends NativeComponent
{
    public function render(): View
    {
        return view('navigate-route-screen');
    }
}
