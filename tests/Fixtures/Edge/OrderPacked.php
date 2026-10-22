<?php

namespace Tests\Fixtures\Edge;

/**
 * An app event without the marker. Fired with event(), it stays
 * with Laravel's listeners and reaches no screen, also when
 * a screen declares an #[On] listener for this very class.
 */
class OrderPacked
{
    public function __construct(
        public int $orderId,
    ) {}
}
