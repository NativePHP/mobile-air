<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Validate;
use Native\Mobile\Edge\NativeComponent;

/** Child component with its own eager rule — bags must scope per instance. */
class ValidationChild extends NativeComponent
{
    #[Validate('required|min:2')]
    public string $nickname = '';

    public bool $childSaved = false;

    public function saveChild(): void
    {
        $this->validate();

        $this->childSaved = true;
    }

    public function pingParent(): void
    {
        $this->emit('saved');
    }

    public function render(): View
    {
        return view('validation-child');
    }
}
