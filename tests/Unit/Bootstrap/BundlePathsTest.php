<?php

use Illuminate\Foundation\Application;

// A Parcel build runs the app's code from inside a read-only phar. The
// bootstrap scripts point .env and public/ at the extracted app directory
// beside it, which the native shell names in NATIVEPHP_BUNDLE_ROOT.

beforeEach(function () {
    require_once __DIR__.'/../../../bootstrap/shared/bundle-paths.php';

    $this->previousRoot = getenv('NATIVEPHP_BUNDLE_ROOT');
});

afterEach(function () {
    $this->previousRoot === false
        ? putenv('NATIVEPHP_BUNDLE_ROOT')
        : putenv('NATIVEPHP_BUNDLE_ROOT='.$this->previousRoot);
});

it('reads .env and public/ from beside the phar', function () {
    putenv('NATIVEPHP_BUNDLE_ROOT=/var/mobile/Documents/app');

    $app = new Application('phar:///var/mobile/Documents/app/__nativephp_app_bundle.phar');

    nativephp_use_bundle_paths($app);

    expect($app->environmentPath())->toBe('/var/mobile/Documents/app')
        ->and($app->publicPath())->toBe('/var/mobile/Documents/app'.DIRECTORY_SEPARATOR.'public')
        ->and($app->basePath())->toBe('phar:///var/mobile/Documents/app/__nativephp_app_bundle.phar');
});

it('leaves an app that is not running from a phar alone', function () {
    putenv('NATIVEPHP_BUNDLE_ROOT=/var/mobile/Documents/app');

    $app = new Application('/var/mobile/Documents/app');

    nativephp_use_bundle_paths($app);

    expect($app->environmentPath())->toBe('/var/mobile/Documents/app')
        ->and($app->publicPath())->toBe('/var/mobile/Documents/app'.DIRECTORY_SEPARATOR.'public');
});

it('does nothing without a bundle root', function () {
    putenv('NATIVEPHP_BUNDLE_ROOT');

    $app = new Application('phar:///somewhere/app.phar');

    nativephp_use_bundle_paths($app);

    expect($app->environmentPath())->toBe('phar:///somewhere/app.phar')
        ->and($app->publicPath())->toBe('phar:///somewhere/app.phar'.DIRECTORY_SEPARATOR.'public');
});
