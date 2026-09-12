<?php

namespace Native\Mobile\Edge\Runtime;

final readonly class RenderTimings
{
    public function __construct(
        public float $renderMs,
        public float $serializeMs,
        public float $publishMs,
    ) {}
}
