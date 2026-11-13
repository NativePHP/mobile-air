<?php

namespace Native\Mobile\Edge\Asgard\Contracts;

use Native\Mobile\Edge\Asgard\UploadRequest;

/**
 * Whatever actually sends a file to an `#[Upload]` method
 * (docs/ASGARD-PROTOCOL.md §10.6).
 *
 * The same deal as ServerTransport: start the request off the UI thread,
 * return the id its answer will carry, and hand the answer to
 * `ServerMethods::deliver()` later, on the UI thread. The answer is a
 * call's answer. Failure to start is signalled by throwing.
 *
 * The request names the file by its path. The transport reads it on its
 * own lane, so the bytes never pass through the UI thread.
 *
 * Its own contract rather than a second method on ServerTransport, so an
 * app or test with only a call transport keeps working.
 */
interface UploadTransport
{
    /**
     * Begin the upload and return the id its response will arrive under.
     *
     * @throws \Throwable when the request could not be started at all
     */
    public function upload(UploadRequest $request): string;
}
