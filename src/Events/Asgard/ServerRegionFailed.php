<?php

namespace Native\Mobile\Events\Asgard;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A request for an `@server` region did not get an answer the client could
 * use (docs/ASGARD-PROTOCOL.md §3.1, §10.2).
 *
 * It covers the first fetch, a refreshServerRegions() and a fetch a push
 * stream signal started. It fires on the screen the region is on (the
 * screen itself, or the screen a child component that owns the region is
 * on), so the screen hears it like any other event, and so do listeners
 * anywhere in the app:
 *
 *     #[On(ServerRegionFailed::class, when: ['region' => 'stats'])]
 *     public function statsFailed(bool $placeholder): void {}
 *
 * The screen draws a frame after it.
 *
 * `placeholder` is true when the region is still showing its placeholder,
 * because no fragment ever landed. That is the case to swap a skeleton for
 * an error message. It is false when an earlier fragment stays on screen.
 *
 * The region is not asked for again on its own. Call
 * refreshServerRegions() on the component that owns it to try again
 * (the component needs `use UsesServer;`).
 *
 * `reason` is one of:
 *
 *  - `timeout`: no answer within the region timeout
 *    (`nativephp.asgard.region_timeout`, or the component's
 *    serverRegionTimeout() if it uses UsesServer).
 *  - `network`: the request never reached the server, or nothing came back.
 *  - `server`: the server answered with an error. `status` is its HTTP
 *    status, when there is one.
 *  - `invalid`: an answer came back, but it failed verification or its
 *    fragment cannot be rendered.
 *
 * An answer that arrives after this fired is dropped. A server screen's own
 * body does not fire this. It has ServerScreenFailed.
 */
class ServerRegionFailed implements BroadcastsGlobally
{
    use Dispatchable;

    public function __construct(
        /** The region's name. */
        public string $region,
        /** The address of the component that owns the region (§4). */
        public string $component,
        public string $reason,
        public ?int $status = null,
        /** The client-local request id. */
        public string $requestId = '',
        /** Whether the region still shows its placeholder. */
        public bool $placeholder = true,
        public ?string $message = null,
    ) {}
}
