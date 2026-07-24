<?php

namespace Native\Mobile\Events\Async;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A background async task completed successfully.
 *
 * Sent from the async PHP lane (or the Jump dev-machine subprocess) via the
 * native event channel and correlated back to its `->finished()` callback by
 * `id`. `$result` is whatever the task closure/handle returned, round-tripped
 * as JSON — so keep it to scalars/arrays (pass large blobs by path or cache key).
 *
 * @see \Native\Mobile\PendingAsyncTask
 * @see \Native\Mobile\Edge\NativeComponent::handleAsyncCompletion()
 */
class AsyncTaskFinished
{
    use Dispatchable;

    public function __construct(
        public string $id,
        public mixed $result = null,
    ) {}
}
