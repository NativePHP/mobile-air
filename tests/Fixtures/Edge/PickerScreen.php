<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\PendingMediaPicker;

/**
 * Fixture for the durable-callbacks suite: every fluent callback shape
 * the media picker supports, plus a private member so the carrier
 * round-trip proves class scope is restored on rebind.
 */
class PickerScreen extends NativeComponent
{
    public ?string $picked = null;

    public string $status = 'idle';

    private string $secretSuffix = '!';

    public function startClosure(): void
    {
        (new PendingMediaPicker)->id('closure-pick')->onSuccess(function (MediaSelected $media) {
            // Captures $this AND touches a private member — the hardest
            // shape to move across a process boundary.
            $this->picked = ($media->files[0]['path'] ?? '?').$this->secretSuffix;
        });
    }

    public function startMethodName(): void
    {
        (new PendingMediaPicker)->id('method-pick')->mediaSelected('onPicked');
    }

    public function startBogusMethod(): void
    {
        (new PendingMediaPicker)->id('bogus-pick')->mediaSelected('noSuchMethod');
    }

    public function onPicked(MediaSelected $media): void
    {
        $this->status = 'picked:'.$media->count;
    }

    public function render(): Element
    {
        return Column::make(
            Text::make('Picked: '.($this->picked ?? 'nothing')),
            Text::make('Status: '.$this->status),
        );
    }
}
