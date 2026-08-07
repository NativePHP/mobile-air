<?php

namespace Native\Mobile\Testing;

use Native\Mobile\Edge\Contracts\TreeObserver;
use Native\Mobile\Edge\TreeObservers;

/**
 * Test double for the TreeObservers broadcast: captures every published
 * tree, UI event, and navigation frame so tests can assert on what the
 * runtime actually emitted — including intermediate frames a final-state
 * assertion can't see.
 *
 *     $spy = TreeSpy::attach();
 *     // ... drive the component/runloop ...
 *     expect($spy->trees)->toHaveCount(2);
 *     expect($spy->events[0]['label'])->toBe('increment');
 *
 * Call TreeObservers::reset() between tests (or attach in beforeEach)
 * to keep spies from leaking across cases.
 */
class TreeSpy implements TreeObserver
{
    /** @var array<int, array{tree: array, uri: string}> oldest first */
    public array $trees = [];

    /** @var array<int, array{event: array, label: ?string}> oldest first */
    public array $events = [];

    /** @var array<int, array> oldest first */
    public array $navs = [];

    /** Construct a spy and register it with the live registry. */
    public static function attach(): static
    {
        $spy = new static;

        TreeObservers::register($spy);

        return $spy;
    }

    public function tree(array $tree, string $uri): void
    {
        $this->trees[] = ['tree' => $tree, 'uri' => $uri];
    }

    public function event(array $event, ?string $label): void
    {
        $this->events[] = ['event' => $event, 'label' => $label];
    }

    public function nav(array $payload): void
    {
        $this->navs[] = $payload;
    }
}
