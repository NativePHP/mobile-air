<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Host embedding ValidationChild — its own bag must stay untouched. */
class ValidationHostScreen extends NativeComponent
{
    public function render(): View
    {
        return view('validation-host');
    }
}
