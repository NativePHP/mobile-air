<?php

namespace Native\Mobile\Concerns;

/**
 * Records the first failure a run hits so callers (native:run --json) can
 * emit a structured result. Recording never changes the human-facing
 * output — failure sites keep their existing error()/note() lines and just
 * call failRun() alongside them.
 */
trait ReportsRunOutcome
{
    protected ?array $runFailure = null;

    protected function failRun(string $stage, string $message, array $extra = []): void
    {
        $this->runFailure ??= array_merge(['stage' => $stage, 'error' => $message], $extra);
    }
}
