<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Memo;
use Native\Mobile\Edge\NativeComponent;

/**
 * #[Memo] child fixture: a list row with props (`slug`, `label`), its own
 * state (`taps`) and a stateful grandchild (MemoTallyChild), counting its
 * renders so the memo tests can see which rows rebuilt.
 */
#[Memo]
class MemoRowChild extends NativeComponent
{
    /** Labels rendered, in order, across the test. */
    public static array $rendered = [];

    public string $slug = '';

    public string $label = '';

    public int $taps = 0;

    public function tap(): void
    {
        $this->taps++;
    }

    public function render(): View
    {
        static::$rendered[] = $this->label;

        return view('memo-row-child');
    }
}
