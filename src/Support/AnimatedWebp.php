<?php

namespace Native\Mobile\Support;

/**
 * GD cannot decode an animated WebP, so the splash installers read its
 * canvas size from the VP8X header and ship the file as it is.
 */
class AnimatedWebp
{
    /**
     * Canvas size of an animated WebP, or null for any other file.
     */
    public static function size(string $path): ?array
    {
        $header = @file_get_contents($path, false, null, 0, 30);

        if (! is_string($header) || strlen($header) < 30
            || ! str_starts_with($header, 'RIFF')
            || substr($header, 8, 8) !== 'WEBPVP8X'
            || (ord($header[20]) & 0x02) === 0) {
            return null;
        }

        return [
            unpack('V', substr($header, 24, 3)."\0")[1] + 1,
            unpack('V', substr($header, 27, 3)."\0")[1] + 1,
        ];
    }
}
