<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A marked event that counts how often PHP has unserialized one,
 * in whichever lane. A test reads the count to tell whether
 * the bytes it sent ever reached unserialize() at all.
 */
class OrderAudited implements BroadcastsGlobally
{
    public static int $woken = 0;

    public function __construct(
        public int $orderId,
    ) {}

    public function __wakeup(): void
    {
        static::$woken++;
    }
}
