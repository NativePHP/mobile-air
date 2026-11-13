<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** A child component that declares a region with the same name as its screen's. */
class FragmentSourceChild extends NativeComponent
{
    public string $label = 'child';

    public function render(): View
    {
        return view('fragment-source-child');
    }
}
