<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

class NestedPollAndOnChild extends NativeComponent
{
    public int $ticks = 0;

    /** @var list<string> */
    public array $pings = [];

    #[Poll(1000)]
    public function tick(): void
    {
        $this->ticks++;
    }

    #[On('PingReceived')]
    public function onPing(array $payload): void
    {
        $message = (string) ($payload['message'] ?? '');
        if ($message !== '') {
            $this->pings[] = $message;
        }
    }

    public function render(): View
    {
        return view('nested-poll-and-on-child');
    }
}
