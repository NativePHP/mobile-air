<?php

namespace Native\Mobile\Edge\Asgard\Contracts;

use Native\Mobile\Edge\Asgard\ServerRequest;

/**
 * Whatever actually performs a `#[Server]` call
 * (docs/ASGARD-SERVER-METHODS.md §3).
 *
 * One method, and it does not return the answer. PHP on device runs a
 * blocking runloop — it waits in `nativephp_element_wait_event()`,
 * dispatches, re-renders, waits again — so a blocking HTTP call inside
 * that loop freezes the UI for the whole round trip, which is exactly
 * what the closure design exists to avoid. The request is handed to the
 * native layer and performed there; the answer comes back later as an
 * `EVENT_NATIVE` (type 20) frame on the event channel the runloop is
 * already blocked on, and `ServerMethods::deliver()` routes it.
 *
 * So an implementation's whole job is: start the request, and return the
 * id the eventual response will carry.
 *
 * Failure to start is signalled by throwing. A `#[Server]` method called
 * with no network runs its client body, fails fast, and runs its closure
 * with a failed `ServerResponse` (§7) — nothing queues in v1.
 */
interface ServerTransport
{
    /**
     * Begin the request and return the id its response will arrive under.
     *
     * The id must be non-empty and unique among in-flight requests; it is
     * the key of the pending-request table. Anything else — session,
     * signing, encryption, the QUERY/POST negotiation of §8 — is the
     * implementation's business and none of PHP's.
     *
     * @throws \Throwable when the request could not be started at all
     */
    public function send(ServerRequest $request): string;
}
