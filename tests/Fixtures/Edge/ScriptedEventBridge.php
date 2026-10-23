<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\FakeBridge;

class ScriptedEventBridge extends FakeBridge
{
    /** @param list<array|null> $events */
    public function __construct(private array $events) {}

    public function elementWaitEvent(int $timeoutMs): ?array
    {
        if ($this->events === []) {
            return ['type' => NativeComponent::EVENT_SHUTDOWN];
        }

        return array_shift($this->events);
    }
}
