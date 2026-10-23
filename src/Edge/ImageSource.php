<?php

namespace Native\Mobile\Edge;

use Native\Mobile\System;

/**
 * Turns a developer-authored image `src` into something the DEVICE can load.
 *
 * `src` reads like a web URL to whoever writes it, so a relative path means
 * "an asset I shipped in `public/`". Who resolves that depends on where PHP is
 * running:
 *
 * - ON DEVICE, PHP and the renderer share a filesystem, so the path travels
 *   over the wire untouched and the native renderer resolves it against the
 *   app's `public/` directory.
 * - UNDER JUMP, PHP is on the developer's machine and the renderer is on a
 *   phone. `public/` only exists here, but `native:jump` is already serving it
 *   over HTTP and the router forwards the phone-reachable `Host` header to
 *   Laravel — so `asset()` yields a URL the device can fetch, and the renderer
 *   treats it as any other remote image.
 *
 * Plugins that accept an image path should route it through here so a `src`
 * means the same thing wherever it's written. Anything already absolute — a
 * device file path, or a value carrying a URL scheme — is passed through
 * untouched: camera captures and gallery picks arrive that way, and rewriting
 * them would break them.
 */
class ImageSource
{
    /** Leading URI scheme per RFC 3986 — `https:`, `file:`, `data:`. */
    private const SCHEME = '#^[a-zA-Z][a-zA-Z0-9+.\-]*:#';

    public static function forDevice(string $src): string
    {
        if ($src === '' || str_starts_with($src, '/') || preg_match(self::SCHEME, $src) === 1) {
            return $src;
        }

        if (! System::runningInJump()) {
            return $src;
        }

        return asset(str_starts_with($src, './') ? substr($src, 2) : $src);
    }
}
