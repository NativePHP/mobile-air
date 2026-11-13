<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\NativeAction;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture with one Asgard region. The region has no transport
 * behind it, so unless a test applies a fragment it stays on its
 * placeholder — the degraded state ASGARD-PROTOCOL.md §3 calls the
 * intended one — while the rest of the screen keeps working.
 */
class ServerRegionScreen extends NativeComponent
{
    public int $refreshes = 0;

    #[NativeAction]
    public function refresh(): void
    {
        $this->refreshes++;
    }

    public function render(): View
    {
        return view('server-region-screen');
    }
}
