<?php

namespace Native\Mobile\Support;

class BundleExclusions
{
    /** Excluded at any depth, including inside vendor packages. */
    public const ANY_DEPTH = [
        '.git',
        '.github',
        '.claude',
        '.idea',
        '.vscode',
        'node_modules',
        'tests',
        '.DS_Store',
        '.gitignore',
        '.gitattributes',
        '.gitkeep',
        '.editorconfig',
    ];

    /** Project-root paths excluded during copy AND removed during cleanup. */
    public const PROJECT = [
        'nativephp',
        'output',
        'build',
        'dist',
        'artifacts',
        'storage/logs',
        'storage/framework',
        'storage/app/native-build',
        'public/storage',
        'database/database.sqlite',
        '*.js',
        '*.md',
        '*.xml',
        '*.jks',
        '*.zip',
        '.env.example',
    ];

    /** Excluded during copy only — kept after composer install regenerates them. */
    public const COPY_ONLY = [
        'bootstrap/cache/*',
    ];

    /** Copied for composer install, removed during cleanup only. */
    public const CLEANUP_ONLY = [
        '*.lock',
        'artisan',
        'auth.json',
    ];

    /**
     * Working directories recreated after the exclusions above have run.
     *
     * Their contents are excluded on purpose, being compiled output and one
     * machine's sessions, but the directories themselves are not optional:
     * composer install runs package:discover in the copied tree, and that
     * boots Laravel.
     */
    public const REQUIRED_DIRECTORIES = [
        'bootstrap/cache',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        // The provider registers this as a view-finder location for every
        // app, and the device's view:cache throws when it is missing.
        // Only native:make-component creates it, so apps without a
        // scaffolded component must still ship the empty dir.
        'resources/views/native',
    ];

    /**
     * Non-runtime patterns matched only inside vendor packages, by file or
     * directory name at any depth. An app can ship any of them anyway by
     * listing the pattern in nativephp.cleanup_keep_vendor_files.
     */
    public const VENDOR_PATTERNS = [
        '*.md',
        'docs',
        '*.yml',
        '*.yaml',
        '*.neon',
        '*.neon.dist',

        // Dependency lock files a package keeps for its own development.
        // Composer and npm never read them from inside vendor/.
        'composer.lock',
        'package-lock.json',
        'yarn.lock',

        // Native plugin sources and prebuilt libraries. The plugin compilers
        // read them from the project's own vendor/ at build time and compile
        // them into the shell. PHP never loads them on the device.
        '*.swift',
        '*.kt',
        '*.kts',
        '*.java',
        '*.gradle',
        '*.m',
        '*.mm',
        '*.a',
        '*.aar',
        '*.jar',
        '*.xcframework',
    ];

    /**
     * Licence and notice files inside vendor packages. They always ship,
     * even when another vendor pattern (*.md) matches them, because most
     * package licences ask for their text to travel with the code. Matched
     * by file or directory name, like VENDOR_PATTERNS.
     */
    public const VENDOR_LICENSE_PATTERNS = [
        'LICENSE*',
        'LICENCE*',
        'COPYING*',
        'NOTICE*',
    ];

    /**
     * VENDOR_PATTERNS without the ones the app asked to keep.
     *
     * @return array<int, string>
     */
    public static function vendorPatterns(): array
    {
        $keep = function_exists('app') && app()->bound('config')
            ? (array) config('nativephp.cleanup_keep_vendor_files', [])
            : [];

        return array_values(array_diff(self::VENDOR_PATTERNS, $keep));
    }

    /**
     * Specific vendor paths to exclude.
     *
     * The two `resources` entries are the same rule pointing both ways: a
     * package's native project template is a build input for the platform it
     * belongs to, and dead weight in a bundle for any other. `nativephp/mobile`
     * carries an Xcode project and an Android Studio project; `supanative/
     * desktop` carries the macOS project. An app targeting phones and the
     * desktop installs both packages, so each build ships one platform's
     * template and drops the other's — desktop does the mirror of this in
     * `SupaNative\Desktop\Platforms\DesktopRunner::FOREIGN_PLATFORM_RESOURCES`.
     *
     * Two packages naming each other's directories by hand is the unlovely part.
     * The tidier arrangement is for `supanative/core` to hold one list that both
     * bundlers read, so neither has to know the other's layout — worth doing,
     * and a bigger change than adding a line here: this list is the mobile
     * package's public surface, referenced by name from its own bundler, its
     * Windows 7-Zip branch and four test files.
     */
    public const VENDOR_PATHS = [
        'vendor/nativephp/mobile/resources',
        'vendor/supanative/desktop/resources',
        'vendor/*/*/vendor',
        'vendor/endroid',
        'vendor/laravel/pint/builds',
        'vendor/livewire/livewire/src/Features/SupportFileUploads/browser_test_image_big.jpg',
        // Install copies core's CA bundle into each native project, where
        // the apps read it, so the one in core's own folder would ride
        // along in the bundle as a second copy which nothing reads.
        'vendor/supanative/core/resources/cacert.pem',
    ];
}
