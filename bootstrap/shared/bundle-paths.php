<?php

use Illuminate\Foundation\Application;

if (! function_exists('nativephp_use_bundle_paths')) {
    /**
     * Point an app that runs from a Parcel bundle at the files kept outside it.
     *
     * The code is read in place from a read-only phar, so .env (which the
     * runtime and OTA updates rewrite) and public/ (which the native asset
     * handler serves) sit beside it in the extracted app directory. The
     * native shell names that directory in NATIVEPHP_BUNDLE_ROOT, and points
     * Laravel's bootstrap caches there with the APP_*_CACHE variables.
     */
    function nativephp_use_bundle_paths(Application $app): void
    {
        $root = getenv('NATIVEPHP_BUNDLE_ROOT');

        if (! is_string($root) || $root === '' || ! str_starts_with($app->basePath(), 'phar://')) {
            return;
        }

        $app->useEnvironmentPath($root);
        $app->usePublicPath($root.DIRECTORY_SEPARATOR.'public');
    }
}
