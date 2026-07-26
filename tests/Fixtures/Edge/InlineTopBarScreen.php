<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Screen whose blade declares an inline `<native:top-bar>` (non-custom). */
class InlineTopBarScreen extends NativeComponent
{
    public int $saves = 0;

    public function navTitle(): string
    {
        return 'Layout Title Should Lose';
    }

    public function save(): void
    {
        $this->saves++;
    }

    public function render(): View
    {
        return view('inline-top-bar-screen');
    }
}
