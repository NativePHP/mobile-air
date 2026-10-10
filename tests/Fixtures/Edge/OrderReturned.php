<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Events\Concerns\BroadcastsGlobally as OldMarker;
use SupaNative\Core\Contracts\BroadcastsGlobally;

/**
 * A marked event that names both the old name of this package
 * and the marker as core has it, as a class does while its
 * app is moved from one name to the other step by step.
 */
class OrderReturned implements BroadcastsGlobally, OldMarker
{
    public function __construct(
        public int $orderId,
    ) {}
}
