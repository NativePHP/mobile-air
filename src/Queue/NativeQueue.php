<?php

namespace Native\Mobile\Queue;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;

/**
 * Native Queue implementation that extends DatabaseQueue.
 *
 * Jobs are stored in SQLite and processed when the native layer
 * triggers the queue worker endpoint.
 */
class NativeQueue extends DatabaseQueue
{
    /**
     * Get the number of jobs in the queue.
     */
    public function size($queue = null): int
    {
        return DB::table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $this->currentTime())
            ->count();
    }

    /**
     * Get the total number of jobs (including reserved).
     */
    public function totalSize($queue = null): int
    {
        return DB::table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->count();
    }

    /**
     * Get the number of reserved (in-progress) jobs.
     */
    public function reservedSize($queue = null): int
    {
        return DB::table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNotNull('reserved_at')
            ->count();
    }

    /**
     * Push a job onto the queue and notify native layer.
     */
    public function push($job, $data = '', $queue = null)
    {
        $result = parent::push($job, $data, $queue);

        $this->notifyNativeLayer();

        return $result;
    }

    /**
     * Push a job onto the queue after a delay.
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $result = parent::later($delay, $job, $data, $queue);
        
        $this->notifyNativeLayer();
        
        return $result;
    }

    /**
     * Notify the native layer that there are jobs to process.
     * This triggers the native queue coordinator to start/continue processing.
     */
    protected function notifyNativeLayer(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        $pending = $this->size();
        
        nativephp_call('Queue.JobsAvailable', json_encode([
            'pending' => $pending,
            'queue' => $this->getQueue(null),
        ]));
    }
}
