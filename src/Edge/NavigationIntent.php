<?php

namespace Native\Mobile\Edge;

class NavigationIntent
{
    const NAVIGATE = 'navigate';

    const BACK = 'back';

    const REPLACE = 'replace';

    const EXIT_WEB = 'exit_web';

    const RESTART = 'restart';

    /**
     * Switch to another tab of a keep-alive tab bar. The tab being left keeps
     * its stack and its component instances; the tab being opened gets back
     * the stack it had, or its root screen when it has never been opened.
     */
    const TAB = 'tab';

    public function __construct(
        public readonly string $type,
        public readonly ?string $uri = null,
        public readonly array $data = [],
        public readonly Transition|string|null $transition = null,
    ) {}
}
