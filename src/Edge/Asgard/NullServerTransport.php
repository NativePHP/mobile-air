<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\Asgard\Contracts\ServerTransport;

/**
 * The transport an app has until a real one is bound.
 *
 * The native bridge function that performs Asgard requests is a separate
 * workstream (docs/ASGARD-SERVER-METHODS.md §3), so out of the box there
 * is nothing to send with. Rather than fatal, this fails the send, which
 * puts a `#[Server]` call on the same path as a device with no network:
 * the client body runs, the request fails fast, and the closure runs with
 * a failed `ServerResponse` (§7).
 *
 * That means a screen written against `#[Server]` behaves correctly — its
 * loading flag clears, its error branch runs — before the transport
 * exists, which is the whole reason the seam is an interface.
 */
class NullServerTransport implements ServerTransport
{
    public function send(ServerRequest $request): string
    {
        throw new \RuntimeException(
            'No Asgard transport is bound, so ['.$request->method.'] on ['.$request->component.'] '
            .'was not sent. Bind '.ServerTransport::class.' to send #[Server] calls.'
        );
    }
}
