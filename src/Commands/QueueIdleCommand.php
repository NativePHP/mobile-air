<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Support\QueueWorkerSignal;

/**
 * Asked by the device queue worker once it has been idle a while: prints one
 * line of JSON saying how many jobs the default queue still holds and when the
 * next one is due, so native can shut the worker down and, if need be, wake it
 * again at the right time. Not meant to be invoked by hand.
 */
class QueueIdleCommand extends Command
{
    protected $signature = 'native:queue:idle';

    protected $description = 'Report whether the device queue worker can sleep (internal)';

    protected $hidden = true;

    public function handle(): int
    {
        $this->line((string) json_encode(QueueWorkerSignal::idleStatus()));

        return self::SUCCESS;
    }
}
