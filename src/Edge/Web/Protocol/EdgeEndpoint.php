<?php

namespace Native\Mobile\Edge\Web\Protocol;

/**
 * Per-installation endpoint paths for the EDGE web target.
 *
 * Mirrors Livewire v4's EndpointResolver hardening: the route prefix is
 * derived from APP_KEY, so every installation gets a unique path
 * (/_edge-3fa9c1d2/update) instead of a well-known one — universal
 * scanners and drive-by probes can't target the update endpoint without
 * already having the page (which embeds the real path in its state).
 *
 * Deterministic from config('app.key'): nothing to publish, survives
 * deploys, and rotating the app key rotates the endpoints.
 */
class EdgeEndpoint
{
    public static function prefix(): string
    {
        $hash = substr(hash('sha256', config('app.key').'edge-endpoint'), 0, 8);

        return '/_edge-'.$hash;
    }

    public static function updatePath(): string
    {
        return static::prefix().'/update';
    }

    /** Route definition path (contains the {file} parameter). */
    public static function fontRoutePath(): string
    {
        return static::prefix().'/fonts/{file}';
    }

    /** Concrete URL for one font file. */
    public static function fontUrl(string $file): string
    {
        return static::prefix().'/fonts/'.rawurlencode($file);
    }

    /** Temporary file upload endpoint (multipart POST, see EdgeUpload). */
    public static function uploadPath(): string
    {
        return static::prefix().'/upload';
    }

    public static function replayPath(): string
    {
        return static::prefix().'/replay';
    }
}
