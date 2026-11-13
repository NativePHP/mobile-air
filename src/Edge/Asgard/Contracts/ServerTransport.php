<?php

namespace Native\Mobile\Edge\Asgard\Contracts;

use Native\Mobile\Edge\Asgard\ServerRequest;

/**
 * Whatever actually performs a `#[Server]` call
 * (docs/ASGARD-SERVER-METHODS.md §3).
 *
 * One method, and it does not return the answer. PHP on device runs a
 * blocking runloop, so a blocking HTTP call inside it freezes the UI for
 * the whole round trip, which is exactly what the closure design exists
 * to avoid. The request runs off the UI thread instead (midgard's
 * HttpServerTransport uses an AsyncTask lane), and the answer comes back
 * later through `ServerMethods::deliver()`, called on the UI thread.
 *
 * So an implementation's whole job is: start the request, and return the
 * id the eventual response will carry. An answer that arrives before
 * send() has returned is held until the call is registered, so a
 * transport that answers inline works too.
 *
 * Failure to start is signalled by throwing. A `#[Server]` method called
 * with no network runs its client body, fails fast, and runs its closure
 * with a failed `ServerResponse` (§7). Nothing queues in v1.
 *
 * A failed answer's `code` decides the reason ServerActionFailed carries:
 * an int HTTP status (100 or more) is `server`, anything else `network`.
 */
interface ServerTransport
{
    /**
     * Begin the request and return the id its response will arrive under.
     *
     * The id must be non-empty and unique among in-flight requests; it is
     * the key of the pending-request table. Anything else (session,
     * signing, encryption, the QUERY/POST negotiation of protocol §8) is
     * the implementation's business.
     *
     * @throws \Throwable when the request could not be started at all
     */
    public function send(ServerRequest $request): string;
}
