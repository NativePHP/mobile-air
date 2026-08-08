<?php

namespace Native\Mobile\Edge\Runtime;

final readonly class Dispatch
{
    /**
     * @param  list<mixed>  $arguments
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $id,
        public ComponentContext $context,
        public DispatchKind $kind,
        public ?string $method = null,
        public array $arguments = [],
        public ?int $eventType = null,
        public ?int $callbackId = null,
        public ?int $nodeId = null,
        public ?string $event = null,
        public array $payload = [],
    ) {}
}
