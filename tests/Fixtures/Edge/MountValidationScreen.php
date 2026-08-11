<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * mount() fails validation. On device this paints the error screen (the
 * runloop's plain catch), so in tests it must BUBBLE, never be silently
 * recorded (regression: review finding 5).
 */
class MountValidationScreen extends NativeComponent
{
    public string $x = '';

    public function mount(): void
    {
        $this->validate(['x' => 'required']);
    }

    public function render(): View
    {
        return view('validation-screen');
    }
}
