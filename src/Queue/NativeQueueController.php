<?php

namespace Native\Mobile\Queue;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

class NativeQueueController
{
    /**
     * Process a single job from the queue.
     * 
     * Called by the native layer when it's ready to process a job.
     * Returns immediately after processing one job (or if queue is empty).
     */
    public function work(Request $request): JsonResponse
    {
        $queue = $request->input('queue', 'default');
        $connection = $request->input('connection', 'native');
        
        $startTime = microtime(true);
        $jobProcessed = false;
        $jobData = null;
        $error = null;

        try {
            /** @var \Illuminate\Queue\QueueManager $manager */
            $manager = app('queue');
            
            /** @var NativeQueue $queueConnection */
            $queueConnection = $manager->connection($connection);
            
            // Pop a single job
            $job = $queueConnection->pop($queue);
            
            if ($job === null) {
                return response()->json([
                    'processed' => false,
                    'reason' => 'empty',
                    'pending' => 0,
                    'duration_ms' => $this->elapsed($startTime),
                ]);
            }

            $jobData = [
                'id' => $job->getJobId(),
                'name' => $job->resolveName(),
                'attempts' => $job->attempts(),
                'queue' => $queue,
            ];

            // Fire processing event
            Event::dispatch(new JobProcessing($connection, $job));

            // Execute the job
            $job->fire();

            // Mark as successful
            $job->delete();
            $jobProcessed = true;

            // Fire processed event
            Event::dispatch(new JobProcessed($connection, $job));

        } catch (Throwable $e) {
            $error = [
                'message' => $e->getMessage(),
                'class' => get_class($e),
            ];

            // Handle failure
            if (isset($job)) {
                if ($job->attempts() >= ($job->maxTries() ?? 3)) {
                    $job->fail($e);
                    Event::dispatch(new JobFailed($connection, $job, $e));
                } else {
                    $job->release(30); // Release back with 30 second delay
                }
            }

            report($e);
        }

        // Get remaining count
        $pending = isset($queueConnection) ? $queueConnection->size($queue) : 0;

        return response()->json([
            'processed' => $jobProcessed,
            'job' => $jobData,
            'error' => $error,
            'pending' => $pending,
            'duration_ms' => $this->elapsed($startTime),
        ]);
    }

    /**
     * Get queue status information.
     */
    public function status(Request $request): JsonResponse
    {
        $queue = $request->input('queue', 'default');
        $connection = $request->input('connection', 'native');

        try {
            /** @var \Illuminate\Queue\QueueManager $manager */
            $manager = app('queue');
            
            /** @var NativeQueue $queueConnection */
            $queueConnection = $manager->connection($connection);

            $pending = $queueConnection->size($queue);
            $reserved = $queueConnection->reservedSize($queue);
            $total = $queueConnection->totalSize($queue);

            // Get failed job count
            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))
                ->where('queue', $queue)
                ->count();

            return response()->json([
                'queue' => $queue,
                'connection' => $connection,
                'pending' => $pending,
                'reserved' => $reserved,
                'total' => $total,
                'failed' => $failed,
                'config' => [
                    'min_delay' => config('nativephp.queue.min_delay', 100),
                    'batch_size' => config('nativephp.queue.batch_size', 10),
                    'poll_interval' => config('nativephp.queue.poll_interval', 2000),
                ],
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear all failed jobs.
     */
    public function clearFailed(Request $request): JsonResponse
    {
        $queue = $request->input('queue');

        $query = DB::table(config('queue.failed.table', 'failed_jobs'));
        
        if ($queue) {
            $query->where('queue', $queue);
        }

        $deleted = $query->delete();

        return response()->json([
            'cleared' => $deleted,
        ]);
    }

    /**
     * Retry a failed job.
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        $failedJob = DB::table(config('queue.failed.table', 'failed_jobs'))
            ->where('id', $id)
            ->first();

        if (! $failedJob) {
            return response()->json([
                'error' => 'Failed job not found',
            ], 404);
        }

        // Re-queue the job
        $payload = json_decode($failedJob->payload, true);
        
        /** @var \Illuminate\Queue\QueueManager $manager */
        $manager = app('queue');
        $manager->connection($failedJob->connection)
            ->pushRaw($failedJob->payload, $failedJob->queue);

        // Remove from failed jobs
        DB::table(config('queue.failed.table', 'failed_jobs'))
            ->where('id', $id)
            ->delete();

        return response()->json([
            'retried' => true,
            'job_id' => $id,
        ]);
    }

    protected function elapsed(float $startTime): int
    {
        return (int) ((microtime(true) - $startTime) * 1000);
    }
}
