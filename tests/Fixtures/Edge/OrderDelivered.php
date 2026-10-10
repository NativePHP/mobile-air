<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A second marked event, for what a handler or a listener fires
 * in answer to a first one. The screen fixture hears it only
 * through a `when` filter, so most of its orders go unheard.
 */
class OrderDelivered implements BroadcastsGlobally
{
    public function __construct(
        public int $orderId,
    ) {}
}
