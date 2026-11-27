<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * An animated WebP header with no frames: the splash installers only read
 * the VP8X header, and GD rejects the file just as it rejects a real one.
 */
final class AnimatedWebpFixture
{
    public static function write(string $path, int $width = 750, int $height = 1334): void
    {
        $vp8x = "\x02\0\0\0".substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
        $chunks = 'VP8X'.pack('V', strlen($vp8x)).$vp8x.'ANIM'.pack('V', 6)."\0\0\0\0\0\0";

        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks);
    }
}
