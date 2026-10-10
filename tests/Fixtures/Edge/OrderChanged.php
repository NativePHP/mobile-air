<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A marked event that carries objects, as PHP events often do.
 * The order is a model in most tests, and the lines are a
 * collection, or a closure, which PHP cannot serialize.
 */
class OrderChanged implements BroadcastsGlobally
{
    public function __construct(
        public object $order,
        public mixed $lines = [],
    ) {}
}
