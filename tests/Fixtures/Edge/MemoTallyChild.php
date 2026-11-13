<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Grandchild fixture with its own state, mounted inside MemoRowChild: a
 * change here must re-render its #[Memo] parent, or it would show stale.
 */
class MemoTallyChild extends NativeComponent
{
    public string $owner = '';

    public int $hits = 0;

    public function hit(): void
    {
        $this->hits++;
    }

    public function render(): View
    {
        return view('memo-tally-child');
    }
}
