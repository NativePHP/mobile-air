<?php

namespace Native\Mobile\Support;

class PlatformBuildResult
{
    public function __construct(
        public readonly string $platform,
        public readonly int $exitCode,
        public readonly float $seconds,
        public readonly string $logPath,
        public readonly bool $interrupted = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->interrupted;
    }
}
