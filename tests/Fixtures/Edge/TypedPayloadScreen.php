<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Contracts\Config\Repository;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;

class TypedPayloadScreen extends NativeComponent
{
    public ?StreamDeltaPayload $delta = null;

    public string $label = '';

    public bool $injected = false;

    #[On('stream:delta')]
    public function onDelta(StreamDeltaPayload $event): void
    {
        $this->delta = $event;
    }

    #[On('stream:labelled')]
    public function onLabelled(StreamDeltaPayload $event, string $label): void
    {
        $this->delta = $event;
        $this->label = $label;
    }

    #[On('stream:injected')]
    public function onInjected(Repository $config): void
    {
        $this->injected = $config->has('app');
    }

    public function render(): View
    {
        return view('typed-payload-screen');
    }
}
