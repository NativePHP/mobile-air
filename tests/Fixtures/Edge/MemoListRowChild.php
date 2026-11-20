<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Memo;
use Native\Mobile\Edge\NativeComponent;

/**
 * #[Memo] row for MemoListScreen. Its root element is keyed by slug when
 * `keyed` is set, and unkeyed (placed by index) when it isn't.
 */
#[Memo]
class MemoListRowChild extends NativeComponent
{
    public string $slug = '';

    public string $label = '';

    public bool $keyed = true;

    public int $taps = 0;

    public function tap(): void
    {
        $this->taps++;
    }

    public function render(): View
    {
        return view($this->keyed ? 'memo-list-keyed-row' : 'memo-list-unkeyed-row');
    }
}
