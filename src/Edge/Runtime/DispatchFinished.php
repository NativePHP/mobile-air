<?php

namespace Native\Mobile\Edge\Runtime;

use Throwable;

final readonly class DispatchFinished
{
    public function __construct(
        public Dispatch $dispatch,
        public float $durationMs,
        public ?Throwable $exception = null,
    ) {}
}
