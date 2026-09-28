<?php

namespace Native\Mobile\Contracts;

/**
 * A typed view of a native event's payload.
 *
 * An #[On] listener parameter typed with a class that implements this is
 * built from the whole payload, so the listener works with typed properties
 * instead of reading array keys. The class owns the mapping and validation:
 * rename wire keys (`request_id` to `$requestId`), apply defaults, and throw
 * on a payload it cannot accept.
 *
 *     #[On('chat-stream:delta')]
 *     public function onDelta(StreamDelta $event): void
 */
interface NativeEventPayload
{
    /** @param  array<string, mixed>  $payload */
    public static function fromNativePayload(array $payload): static;
}
