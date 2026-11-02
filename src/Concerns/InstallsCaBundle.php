<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\File;
use SupaNative\Core\Support\CaBundle;

/**
 * Core keeps the CA bundle PHP uses for HTTPS current. The install
 * copies it into a new project, and each build copies it again,
 * so an app ships the bundle core has on the day it's built.
 */
trait InstallsCaBundle
{
    /**
     * Xcode copies resources in NativePHP/ into the app, and the app
     * finds the bundle there by its name, cacert.pem, then points
     * PHP's curl and openssl settings at it when it starts up.
     */
    protected function installIosCaBundle(): bool
    {
        return File::copy(CaBundle::path(), base_path('nativephp/ios/NativePHP/cacert.pem'));
    }

    /**
     * LaravelEnvironment reads the bundle from the APK's assets by its
     * name, cacert.pem. Git keeps no empty folders, so the template
     * has no assets folder, and a new project needs one created.
     */
    protected function installAndroidCaBundle(): bool
    {
        $assets = base_path('nativephp/android/app/src/main/assets');

        File::ensureDirectoryExists($assets);

        return File::copy(CaBundle::path(), $assets.'/cacert.pem');
    }
}
