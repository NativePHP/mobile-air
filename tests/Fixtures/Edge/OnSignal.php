<?php

namespace Tests\Fixtures\Edge;

use Attribute;
use Native\Mobile\Attributes\On;

/**
 * Stand-in for a plugin attribute that extends On. It stores the
 * event name without the `native:` prefix, so its listeners
 * sit under the plain name, which is looked up first.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class OnSignal extends On
{
    public function __construct(string $event)
    {
        $this->event = $event;
    }
}
