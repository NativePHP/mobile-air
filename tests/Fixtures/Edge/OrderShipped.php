<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * An app event that is marked to broadcast globally, as an app would
 * write it. PHP fires it with event(), and the live screen hears
 * it in its #[On] listeners next to what Laravel listens for.
 */
class OrderShipped implements BroadcastsGlobally
{
    public function __construct(
        public int $orderId,
        public string $status,
    ) {}
}
