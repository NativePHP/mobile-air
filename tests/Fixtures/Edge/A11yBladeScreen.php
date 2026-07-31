<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

/** Blade-tag counterpart of A11yScreen — exercises the collector's central a11y attr hydration. */
class A11yBladeScreen extends NativeComponent
{
    public function noop(): void
    {
        //
    }

    public function render(): Element|View
    {
        return view('a11y-blade-screen');
    }
}
