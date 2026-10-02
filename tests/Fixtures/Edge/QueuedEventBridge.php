<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\FakeBridge;

/**
 * A FakeBridge with a real event queue, for driving the router's
 * navigation loop. Like the C runtime, a reset discards whatever is still
 * queued, and a non-blocking wait returns null when nothing is there. A
 * blocking wait on an empty queue ends the screen with a shutdown event,
 * which unwinds the stack so the test run finishes.
 */
class QueuedEventBridge extends FakeBridge
{
    /** @var list<array> */
    public array $queue = [];

    public function post(array $event): void
    {
        $this->queue[] = $event;
    }

    public function elementWaitEvent(int $timeoutMs): ?array
    {
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        return $timeoutMs < 0 ? ['type' => NativeComponent::EVENT_SHUTDOWN] : null;
    }

    public function elementReset(): void
    {
        parent::elementReset();

        $this->queue = [];
    }

    /**
     * The `current_uri` of every published native stack frame, oldest
     * first, tagged with the screen text so frames can be told apart.
     *
     * @return list<string>
     */
    public function stackFrames(): array
    {
        $frames = [];

        foreach ($this->publishes as $tree) {
            $uri = $tree['props']['current_uri'] ?? null;
            if ($uri !== null) {
                $frames[] = trim($uri.' '.implode(' ', self::texts($tree)));
            }
        }

        return $frames;
    }

    /** @return list<string> */
    private static function texts(array $node): array
    {
        $texts = isset($node['props']['text']) ? [$node['props']['text']] : [];

        foreach ($node['children'] ?? [] as $child) {
            array_push($texts, ...self::texts($child));
        }

        return $texts;
    }
}
