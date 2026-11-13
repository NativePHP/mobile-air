<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Asgard\ServerScreenFailed;

/** Where a server screen that gave up lands: it shows what it heard. */
class ScreenFailureLanding extends NativeComponent
{
    /** @var list<array> */
    public array $heard = [];

    /** @var list<string> */
    public array $timeouts = [];

    #[On(ServerScreenFailed::class)]
    public function serverScreenFailed(string $uri, string $address, string $reason, ?int $status): void
    {
        $this->heard[] = compact('uri', 'address', 'reason', 'status');
    }

    #[On(ServerScreenFailed::class, when: ['reason' => 'timeout'])]
    public function serverScreenTimedOut(string $address): void
    {
        $this->timeouts[] = $address;
    }

    public function render(): Element
    {
        $last = end($this->heard);

        return Text::make($last === false ? 'Home' : "Gave up on {$last['address']}: {$last['reason']}");
    }
}
