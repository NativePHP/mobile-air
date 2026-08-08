<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Gallery\MediaSelected;

/**
 * A DIFFERENT screen with a method named identically to PickerScreen's
 * callback target — the cold-start-on-the-wrong-screen hazard for
 * method-name string callbacks.
 */
class WrongPickScreen extends NativeComponent
{
    public string $status = 'idle';

    public function onPicked(MediaSelected $media): void
    {
        $this->status = 'WRONGLY-FIRED';
    }

    public function render(): Element
    {
        return Column::make(Text::make('Status: '.$this->status));
    }
}
