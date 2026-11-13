<?php

namespace Tests\Fixtures\Edge;

use Closure;
use Illuminate\View\View;
use Native\Mobile\Attributes\Memo;
use Native\Mobile\Edge\NativeComponent;

/**
 * #[Memo] child whose state can't be serialised (a closure), so it can't be
 * fingerprinted and must render every frame.
 */
#[Memo]
class MemoClosureChild extends NativeComponent
{
    public static int $renders = 0;

    public ?Closure $format = null;

    public function mount(): void
    {
        $this->format = fn (string $value): string => mb_strtoupper($value);
    }

    public function render(): View
    {
        static::$renders++;

        return view('memo-closure-child');
    }
}
