<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * A screen whose single root IS the scroll-view, with the fab declared
 * inside it — the fab cannot stay a scroll child (it would render as a
 * list item), so this shape keeps the Stack overlay.
 */
class ScrollRootFabScreen extends NativeComponent
{
    public array $tasks = ['One', 'Two', 'Three'];

    public function create(): void
    {
        $this->tasks[] = 'Task '.(count($this->tasks) + 1);
    }

    public function render(): View
    {
        return view('scroll-root-fab-screen');
    }
}
