<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** A child that emits `saved` when its button is tapped. */
class SavingChild extends NativeComponent
{
    public function save(): void
    {
        $this->emit('saved', 'draft');
    }

    public function render(): View
    {
        return view('saving-child');
    }
}
