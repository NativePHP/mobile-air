<?php

namespace Native\Mobile\Edge\Runtime;

final readonly class Dispatch
{
    /**
     * @param  string|null  $method  Best-effort label: the component method the
     *                               dispatch resolves to at lookup time. Null
     *                               when nothing matches — or when the event is
     *                               consumed elsewhere (a package handler
     *                               returning Handled, fluent ->on() closures,
     *                               one-shot bridge callbacks, the __deeplink
     *                               navigation branch). Tooling should treat it
     *                               as a hint, never as a trace of what ran.
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
