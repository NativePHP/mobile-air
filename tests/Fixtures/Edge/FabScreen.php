<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen with a top-level `<native:fab>`. */
class FabScreen extends NativeComponent
{
    public int $created = 0;

    public function create(): void
    {
        $this->created++;
    }

    public function render(): View
    {
        return view('fab-screen');
    }
}
