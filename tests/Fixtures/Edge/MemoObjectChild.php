<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Memo;
use Native\Mobile\Edge\NativeComponent;

/**
 * #[Memo] child with an object prop and an enum prop, counting its renders.
 */
#[Memo]
class MemoObjectChild extends NativeComponent
{
    public static int $renders = 0;

    public ?MemoItem $item = null;

    public MemoTone $tone = MemoTone::Calm;

    public function render(): View
    {
        static::$renders++;

        return view('memo-object-child');
    }
}
