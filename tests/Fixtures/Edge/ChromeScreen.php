<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class ChromeScreen extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Chrome Demo';
    }

    /**
     * Renders through the View path on purpose — layout chrome wrapping
     * (wrapWithChrome) happens in view()/fromView(), not for raw Element
     * returns.
     */
    public function render(): View
    {
        return view('chrome-screen');
    }
}
