<?php

namespace Tests\Fixtures\Edge;

use SupaNative\Core\Contracts\BroadcastsGlobally;

/**
 * A marked event that names the marker as core has it, where every
 * other fixture uses the old name of this package. It is marked
 * for every platform, but it is no instance of the old name.
 */
class OrderArchived implements BroadcastsGlobally
{
    public function __construct(
        public int $orderId,
    ) {}
}
