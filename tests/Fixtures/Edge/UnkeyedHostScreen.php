<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture rendering UNKEYED same-tag children — pins down the
 * positional-identity contract (tag + occurrence index): reordering the
 * data leaves state with the position, not the datum.
 */
class UnkeyedHostScreen extends NativeComponent
{
    /** @var list<string> */
    public array $names = ['a', 'b'];

    public function render(): View
    {
        return view('unkeyed-host-screen');
    }
}
