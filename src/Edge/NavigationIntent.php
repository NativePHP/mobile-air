<?php

namespace Native\Mobile\Edge;

class NavigationIntent
{
    const NAVIGATE = 'navigate';

    const BACK = 'back';

    const REPLACE = 'replace';

    const EXIT_WEB = 'exit_web';

    const RESTART = 'restart';

    public function __construct(
        public readonly string $type,
        public readonly ?string $uri = null,
        public readonly array $data = [],
        public readonly Transition|string|null $transition = null,
        // A BACK answering a system back: native navigation has already
        // popped, so what the user does next is on the screen below.
        public readonly bool $fromSystemBack = false,
    ) {}
}
