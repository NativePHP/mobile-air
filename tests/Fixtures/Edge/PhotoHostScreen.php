<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen fixture hosting a keyed list of PhotoChild components. */
class PhotoHostScreen extends NativeComponent
{
    /** @var list<string> */
    public array $labels = ['a', 'b'];

    public function render(): View
    {
        return view('photo-host-screen');
    }
}
