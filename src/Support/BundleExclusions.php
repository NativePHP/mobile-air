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

    /** Non-runtime patterns inside vendor packages. */
    public const VENDOR_PATTERNS = [
        '*.md',
        'LICENSE*',
        'docs',
        '*.yml',
        '*.yaml',
        '*.neon',
        '*.neon.dist',
    ];

    /** Specific vendor paths to exclude. */
    public const VENDOR_PATHS = [
        'vendor/nativephp/mobile/resources',
        'vendor/*/*/vendor',
        'vendor/endroid',
        'vendor/laravel/pint/builds',
        'vendor/livewire/livewire/src/Features/SupportFileUploads/browser_test_image_big.jpg',
    ];
}
