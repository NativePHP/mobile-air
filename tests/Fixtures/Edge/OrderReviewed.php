<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A marked event whose properties may all be null, as an app
 * writes one for what a customer left blank. Each null is
 * to arrive as a null, on a screen and in another lane.
 */
class OrderReviewed implements BroadcastsGlobally
{
    public function __construct(
        public ?int $orderId,
        public ?string $comment,
        public ?bool $recommended,
        public ?float $stars,
    ) {}
}
