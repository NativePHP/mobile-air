<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for kept #[Memo] rows that move: a list under a keyed
 * column, so a row's place is its key path and its index, not a positional
 * id. With `keyed` each row's root element carries a key of its own.
 */
class MemoListScreen extends NativeComponent
{
    public string $title = 'List';

    /** @var array<string, string> slug => label */
    public array $rows = ['b' => 'Bravo', 'c' => 'Charlie'];

    public bool $keyed = true;

    public function render(): View
    {
        return view('memo-list-screen');
    }
}
