<?php

namespace Native\Mobile\Edge\Runtime;

final readonly class ComponentPublished
{
    public function __construct(
        public ComponentContext $context,
        public ?RenderTimings $timings = null,
    ) {}
}
