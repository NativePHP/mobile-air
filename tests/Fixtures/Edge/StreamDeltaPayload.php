<?php

namespace Tests\Fixtures\Edge;

use InvalidArgumentException;
use Native\Mobile\Contracts\NativeEventPayload;

final readonly class StreamDeltaPayload implements NativeEventPayload
{
    public function __construct(
        public string $requestId,
        public string $text,
    ) {}

    public static function fromNativePayload(array $payload): static
    {
        if (! is_string($payload['request_id'] ?? null)) {
            throw new InvalidArgumentException('A stream delta needs a request_id.');
        }

        return new self($payload['request_id'], is_string($payload['text'] ?? null) ? $payload['text'] : '');
    }
}
