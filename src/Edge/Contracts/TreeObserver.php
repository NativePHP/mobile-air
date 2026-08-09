<?php

namespace Native\Mobile\Edge\Contracts;

/**
 * Observer of the published element-tree stream.
 *
 * The runtime broadcasts three kinds of frames through TreeObservers:
 * every published tree, every user-facing UI event (system frames like
 * hot reload are filtered at the tap), and navigation intents. Consumers
 * register via TreeObservers::register() — core ships none; packages
 * (session recording, live mirroring, analytics) bring their own.
 */
interface TreeObserver
{
    /** A full element tree was published for $uri. */
    public function tree(array $tree, string $uri): void;

    /** A UI event was dispatched; $label is the resolved method or protocol event name. */
    public function event(array $event, ?string $label): void;

    /** A navigation intent left the current screen. */
    public function nav(array $payload): void;
}
