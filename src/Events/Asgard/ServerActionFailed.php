<?php

namespace Native\Mobile\Events\Asgard;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A server action did not get an answer the client could use
 * (docs/ASGARD-PROTOCOL.md §10.2, docs/ASGARD-SERVER-METHODS.md §7).
 *
 * It covers a tap inside a fragment or on server chrome, and a `#[Server]`
 * method call. It fires on the screen the action was sent from (the
 * screen itself, or the screen a child component that sent it is on), so
 * the screen hears it like any other event, and so do listeners anywhere
 * in the app:
 *
 *     #[On(ServerActionFailed::class, when: ['action' => 'like'])]
 *     public function likeFailed(string $reason): void {}
 *
 *     Event::listen(ServerActionFailed::class, fn ($e) => ...);
 *
 * The screen draws a frame after it. A `#[Server]` method's closure still
 * runs with a failed ServerResponse as well.
 *
 * `reason` is one of:
 *
 *  - `timeout`: no answer within the action timeout
 *    (`nativephp.asgard.action_timeout`, or the component's
 *    serverActionTimeout() if it uses UsesServer).
 *  - `network`: the request never reached the server, or nothing came back.
 *  - `server`: the server answered with an error. `status` is its HTTP
 *    status, when there is one.
 *  - `invalid`: an answer came back, but it failed verification or its
 *    fragment cannot be rendered.
 *
 * An answer that arrives after this fired is dropped.
 */
class ServerActionFailed implements BroadcastsGlobally
{
    use Dispatchable;

    public function __construct(
        /** The action's `call`, or the `#[Server]` method's name. */
        public string $action,
        /** The address of the component that sent it (§4). */
        public string $component,
        /** The region it was sent from. Null for a `#[Server]` method. */
        public ?string $region,
        public string $reason,
        public ?int $status = null,
        /** The client-local request id. */
        public string $requestId = '',
        public ?string $message = null,
    ) {}
}
