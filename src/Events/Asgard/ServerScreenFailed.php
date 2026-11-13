<?php

namespace Native\Mobile\Events\Asgard;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A server screen gave up before its body arrived
 * (docs/ASGARD-PROTOCOL.md §12.1).
 *
 * The app has already left the screen when this fires. It went back to the
 * screen below, or replaced itself with the app's `/` route when there was
 * none (a cold deep link straight onto a server screen). The event is
 * fired once that screen is the live one, so it hears it like any other
 * event, as well as listeners anywhere in the app:
 *
 *     Event::listen(ServerScreenFailed::class, fn ($e) => ...);
 *
 *     #[On(ServerScreenFailed::class)]
 *     public function serverScreenFailed(string $reason): void {}
 *
 * `reason` is `timeout` (no body within `nativephp.asgard.screen_timeout`,
 * or the screen's screenTimeout()), `network`, `server` or `invalid`.
 * `status` is the HTTP status the server answered with, when there is one.
 */
class ServerScreenFailed implements BroadcastsGlobally
{
    use Dispatchable;

    public function __construct(
        /** The route the screen was opened at, query string included. */
        public string $uri,
        /** The server screen's address. */
        public string $address,
        public string $reason,
        public ?int $status = null,
        public ?string $message = null,
    ) {}
}
