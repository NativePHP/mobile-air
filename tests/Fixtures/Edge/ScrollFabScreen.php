<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The reported scroll-regression shape: hoisted `<native:top-bar>`,
 * a full-size `<scroll-view>` of cards, and a top-level `<native:fab>`.
 */
class ScrollFabScreen extends NativeComponent
{
    public array $tasks = ['One', 'Two', 'Three'];

    public function create(): void
    {
        $this->tasks[] = 'Task '.(count($this->tasks) + 1);
    }

    public function render(): View
    {
        return view('scroll-fab-screen');
    }
}
