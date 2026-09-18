<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Foundation\Events\Dispatchable;

/** Custom outcome event for the ->event(Custom::class) override tests. */
class CustomPickEvent
{
    use Dispatchable;

    public function __construct(
        public bool $success = false,
        public int $count = 0,
        public ?string $id = null,
    ) {}
}
