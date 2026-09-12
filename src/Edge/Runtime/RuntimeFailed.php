<?php

namespace Native\Mobile\Edge\Runtime;

use Throwable;

final readonly class RuntimeFailed
{
    public function __construct(
        public ComponentContext $context,
        public Throwable $exception,
    ) {}
}
