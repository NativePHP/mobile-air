<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for the #[Memo] tests: a keyed list of rows, as #[Memo]
 * children or (with `plain`) as the same children without it.
 */
class MemoHostScreen extends NativeComponent
{
    public string $title = 'Host';

    /** @var array<string, string> slug => label */
    public array $rows = ['a' => 'Alpha', 'b' => 'Bravo', 'c' => 'Charlie'];

    public bool $plain = false;

    public bool $withClosureChild = false;

    public bool $withObjectChild = false;

    public ?MemoItem $item = null;

    public MemoTone $tone = MemoTone::Calm;

    public function render(): View
    {
        return view('memo-host-screen');
    }
}
