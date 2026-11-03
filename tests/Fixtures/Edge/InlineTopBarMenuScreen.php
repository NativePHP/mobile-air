<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Inline `<native:top-bar>` whose action nests a row, sections and a submenu in Blade. */
class InlineTopBarMenuScreen extends NativeComponent
{
    public string $display = 'list';

    public int $deletes = 0;

    public function viewAs(string $view): void
    {
        $this->display = $view;
    }

    public function delete(): void
    {
        $this->deletes++;
    }

    public function render(): View
    {
        return view('inline-top-bar-menu-screen');
    }
}
