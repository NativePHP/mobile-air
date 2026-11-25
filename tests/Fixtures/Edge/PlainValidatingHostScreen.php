<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;

/** Screen fixture without ValidatesInput that hosts ValidatingChild. */
class PlainValidatingHostScreen extends NativeComponent
{
    #[On('draft-saved')]
    public function draftSaved(): void
    {
        Validator::make(['title' => ''], ['title' => 'required'])->validate();
    }

    public function render(): View
    {
        return view('plain-validating-host-screen');
    }
}
