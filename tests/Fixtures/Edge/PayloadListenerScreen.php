<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;

class PayloadListenerScreen extends NativeComponent
{
    /** @var array<string, mixed> */
    public array $wholePayload = [];

    /** @var list<string> */
    public array $items = [];

    #[On('WholePayloadReceived')]
    public function onWholePayload(array $payload): void
    {
        $this->wholePayload = $payload;
    }

    #[On('ItemsReceived')]
    public function onItems(array $items): void
    {
        $this->items = $items;
    }

    public function render(): View
    {
        return view('payload-listener-screen');
    }
}
