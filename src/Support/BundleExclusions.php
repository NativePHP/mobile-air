<?php

namespace Native\Mobile\Support;

class BundleExclusions
{
    /** Excluded at any depth, including inside vendor packages. */
    public const ANY_DEPTH = [
        '.git',
        '.github',
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
    ];

    /** Non-runtime patterns matched only inside vendor packages. */
    public const VENDOR_PATTERNS = [
        '*.md',
        'LICENSE*',
        'docs',
        '*.yml',
        '*.yaml',
        '*.neon',
        '*.neon.dist',
    ];

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
    ];
}
