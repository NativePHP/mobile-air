<?php

namespace Native\Mobile\Queue\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the queue becomes empty (all jobs processed).
 * 
 * Listen in Livewire:
 * 
 * #[On('native:Native\Mobile\Queue\Events\QueueEmpty')]
 * public function onQueueEmpty($queue, $processedCount)
 * {
 *     // All jobs done
 * }
 */
class QueueEmpty
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $queue,
        public int $processedCount,
    ) {}
}
