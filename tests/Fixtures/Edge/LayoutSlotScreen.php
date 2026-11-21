<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen whose blade uses an ordinary Blade component (`<x-layout>`) as
 * its layout: the top bar and bottom nav live in the component's view,
 * the screen fills its default slot and a named `actions` slot.
 */
class LayoutSlotScreen extends NativeComponent
{
    public int $infoShown = 0;

    public function showInfo(): void
    {
        $this->infoShown++;
    }

    public function render(): View
    {
        return view('layout-slot-screen');
    }
}
