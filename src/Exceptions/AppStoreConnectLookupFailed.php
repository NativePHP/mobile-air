<?php

namespace Native\Mobile\Exceptions;

use RuntimeException;
use Throwable;

/**
 * App Store Connect could not tell us the latest build number, even after a retry.
 *
 * The build stops here instead of falling back to the local build number: on an
 * app that has uploaded before, a guessed number almost always matches one
 * Apple already has, and the upload is rejected after the whole build has run.
 */
class AppStoreConnectLookupFailed extends RuntimeException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct(
            "Could not get the latest build number from App Store Connect ({$reason}). ".
            'Stopping rather than guessing one, as a guessed number would likely clash with a build already uploaded.',
            previous: $previous,
        );
    }
}
