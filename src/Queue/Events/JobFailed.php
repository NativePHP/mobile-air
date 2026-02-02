<?php

namespace Native\Mobile\Queue\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a queued job fails.
 * 
 * Listen in Livewire:
 * 
 * #[On('native:Native\Mobile\Queue\Events\JobFailed')]
 * public function onJobFailed($jobId, $jobName, $error, $willRetry)
 * {
 *     // Handle failure
 * }
 */
class JobFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $jobId,
        public string $jobName,
        public string $error,
        public bool $willRetry,
        public int $attempts,
    ) {}
}
