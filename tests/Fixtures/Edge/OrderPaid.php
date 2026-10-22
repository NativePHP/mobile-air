<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A marked event that waits for the database transaction around
 * it. Laravel holds it until that transaction has committed,
 * and only then it reaches a listener or leaves its lane.
 */
class OrderPaid implements BroadcastsGlobally, ShouldDispatchAfterCommit
{
    public function __construct(
        public int $orderId,
    ) {}
}
