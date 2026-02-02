<?php

namespace Native\Mobile\Queue\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a queued job completes successfully.
 * 
 * Listen in Livewire:
 * 
 * #[On('native:Native\Mobile\Queue\Events\JobCompleted')]
 * public function onJobCompleted($jobId, $jobName, $queue, $durationMs)
 * {
 *     // Handle completion
 * }
 */
class JobCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $jobId,
        public string $jobName,
        public string $queue,
        public int $durationMs,
    ) {}
}
