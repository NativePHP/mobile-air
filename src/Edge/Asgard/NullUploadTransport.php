<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\Asgard\Contracts\UploadTransport;

/**
 * The upload transport an app has until a real one is bound. Like
 * NullServerTransport, it fails the send, so uploadToServer() fails as it
 * would with no network: ServerActionFailed fires with reason `network`.
 */
class NullUploadTransport implements UploadTransport
{
    public function upload(UploadRequest $request): string
    {
        throw new \RuntimeException(
            'No Asgard upload transport is bound, so the upload to ['.$request->method.'] on ['.$request->component.'] '
            .'was not sent. Bind '.UploadTransport::class.' to send uploads.'
        );
    }
}
