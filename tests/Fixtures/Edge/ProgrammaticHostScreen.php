<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen fixture hosting a child that renders programmatically. */
class ProgrammaticHostScreen extends NativeComponent
{
    public int $screenTaps = 0;

    public function tapIt(): void
    {
        // Same method name as the child's — proves the dispatch resolves
        // ownership by registry, not by name.
        $this->screenTaps++;
    }

    public function render(): View
    {
        return view('programmatic-host-screen');
    }
}
