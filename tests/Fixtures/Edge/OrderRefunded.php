<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A marked event that serializes its model as a queued job does.
 * Only the key of the order travels with it, and the lane that
 * takes the event fetches the row again from the database.
 */
class OrderRefunded implements BroadcastsGlobally
{
    use SerializesModels;

    public function __construct(
        public Order $order,
    ) {}
}
