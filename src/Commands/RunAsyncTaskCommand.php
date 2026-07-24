<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Support\AsyncTaskRunner;

/**
 * Runs a single dispatched async task, identified by its id, inside the
 * background PHP context (the device async lane, or a Jump dev-machine
 * subprocess). Not meant to be invoked by hand — {@see \Native\Mobile\Support\AsyncTaskTransport}
 * dispatches it.
 *
 * `--jump` marks the run as a Jump subprocess so completion is written to the
 * spool the dev-server runloop drains, instead of the native bridge.
 */
class RunAsyncTaskCommand extends Command
{
    protected $signature = 'native:async:run {--id= : The dispatched task id} {--jump : Deliver completion via the Jump spool}';

    protected $description = 'Execute a background async task (internal)';

    protected $hidden = true;

    public function handle(): int
    {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('Missing required --id option.');

            return self::FAILURE;
        }

        if ($this->option('jump')) {
            putenv('NATIVEPHP_ASYNC_JUMP=1');
        }

        AsyncTaskRunner::run($id);

        return self::SUCCESS;
    }
}
