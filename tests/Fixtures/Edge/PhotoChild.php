<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Camera;

/**
 * Child fixture that starts a native call from its own handler and keeps
 * the result in its own state, through a fluent callback that uses $this.
 */
class PhotoChild extends NativeComponent
{
    public string $label = '';

    public ?string $photo = null;

    public function takePhoto(): void
    {
        Camera::getPhoto()
            ->id('photo-'.$this->label)
            ->photoTaken(fn ($event) => $this->photo = $event->path);
    }

    public function render(): View
    {
        return view('photo-child');
    }
}
