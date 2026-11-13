<?php

namespace Native\Mobile\Edge\Asgard;

/**
 * One file on its way to an `#[Upload]` method (protocol §10.6).
 *
 * Like a ServerRequest it carries the method, args, state and platform.
 * It also names the file: its resolved path on the device, its size when
 * it was checked, and a filename and mime type the server only shows.
 * The server sniffs the real type from the bytes.
 *
 * The bytes are not here. The transport reads them from `path` on its own
 * lane, so a large file never sits in the UI thread's memory or in a
 * serialized closure.
 */
final class UploadRequest
{
    public function __construct(
        public readonly string $component,
        public readonly string $method,
        public readonly array $args,
        public readonly array $state,
        public readonly string $platform,
        public readonly string $path,
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $size,
    ) {}

    /** The frame header, without the bytes. A transport adds `protocol`. */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'method' => $this->method,
            'args' => $this->args,
            'state' => $this->state,
            'platform' => $this->platform,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }

    /**
     * A best guess from the extension. It is only a hint: the server
     * decides the type from the bytes.
     */
    public static function guessMime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'txt' => 'text/plain',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }
}
