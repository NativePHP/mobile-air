<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Concerns\PreparesBuild;
use Native\Mobile\Support\BundleFileManager;
use Tests\TestCase;
use ZipArchive;

class ReleaseBuildBundleTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        // realpath() matters: sys_get_temp_dir() is a symlink on macOS and
        // addDirectoryToZip() derives archive paths from getRealPath(), so
        // an unresolved base path would mangle every entry in the zip.
        $this->testProjectPath = realpath(sys_get_temp_dir()).'/nativephp_release_bundle_test_'.uniqid();

        File::ensureDirectoryExists($this->testProjectPath);
        app()->setBasePath($this->testProjectPath);

        config([
            'nativephp.cleanup_exclude_files' => [],
            'nativephp.runtime.mode' => 'persistent',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);

        parent::tearDown();
    }

    public function test_ios_laravel_copy_excludes_cached_bootstrap_files_but_keeps_cache_directory(): void
    {
        File::ensureDirectoryExists($this->testProjectPath.'/app');
        File::ensureDirectoryExists($this->testProjectPath.'/bootstrap/cache');

        File::put($this->testProjectPath.'/app/Example.php', '<?php');
        File::put($this->testProjectPath.'/bootstrap/cache/packages.php', '<?php return [];');
        File::put($this->testProjectPath.'/bootstrap/cache/services.php', '<?php return [];');

        // The same copy call bundleLaravelApp() makes. The inline
        // copyLaravelAppIntoIosApp() this test reflected on was
        // replaced by the shared BundleFileManager wiring.
        BundleFileManager::copy(
            base_path(),
            $this->testProjectPath.'/nativephp/ios/laravel/',
            config('nativephp.cleanup_exclude_files', [])
        );

        $this->assertFileExists($this->testProjectPath.'/nativephp/ios/laravel/app/Example.php');
        $this->assertDirectoryExists($this->testProjectPath.'/nativephp/ios/laravel/bootstrap/cache');
        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/ios/laravel/bootstrap/cache/packages.php');
        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/ios/laravel/bootstrap/cache/services.php');
    }

    public function test_android_release_bundle_excludes_cached_bootstrap_files_and_recreates_cache_directory(): void
    {
        // Composer is faked but rsync is not — the copy must really run
        // through BundleFileManager so the zip assertions below reflect
        // the bundle an actual build would produce.
        Process::fake([
            'composer install*' => Process::result(),
            'composer dump-autoload*' => Process::result(),
        ]);

        config(['nativephp.cleanup_exclude_files' => ['secret']]);

        $this->createAndroidProjectFixture();

        $builder = new ReleaseBuildTester;
        $builder->testPrepareLaravelBundle();

        Process::assertRan('composer install --no-dev --no-interaction');
        Process::assertRan('composer dump-autoload --optimize --classmap-authoritative');

        $zipPath = $this->testProjectPath.'/nativephp/android/app/src/main/assets/laravel_bundle.zip';
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        $this->assertFalse($zip->statName('bootstrap/cache/services.php'));
        $this->assertFalse($zip->statName('bootstrap/cache/packages.php'));
        $this->assertNotFalse($zip->statName('bootstrap/cache/'));

        // The provider registers this location unconditionally, so the
        // bundle must ship it even when the app never scaffolded a
        // native component, or view:cache throws on device (#322).
        $this->assertNotFalse($zip->statName('resources/views/native/'));

        // Delegation to BundleFileManager, asserted by outcome: config
        // excludes and vendor slimming patterns shape the final zip.
        $this->assertNotFalse($zip->statName('app/Example.php'));
        $this->assertNotFalse($zip->statName('vendor/acme/pkg/src/Pkg.php'));
        $this->assertFalse($zip->statName('vendor/acme/pkg/README.md'));
        $this->assertFalse($zip->statName('secret/api-key.txt'));

        // Cleanup-only files survive the copy so composer install can use
        // them, then the cleanup pass removes them from the final bundle.
        // The Android artisan.php bootstrap must survive that pass.
        $this->assertFalse($zip->statName('artisan'));
        $this->assertFalse($zip->statName('composer.lock'));
        $this->assertNotFalse($zip->statName('artisan.php'));
        $this->assertNotFalse($zip->statName('.version'));

        // Runtime dirs are re-added as empty entries after cleanup strips them.
        $this->assertNotFalse($zip->statName('storage/framework/views/'));

        $zip->close();
    }

    public function test_runtime_storage_dirs_exist_before_composer_install_runs(): void
    {
        // storage/framework is excluded from the copy, but composer install
        // triggers package:discover, which boots Laravel and resolves the
        // Blade compiler's cache path — realpath(storage/framework/views)
        // must not be false at that point (#245). The fake closure runs at
        // install time, so it observes the tree exactly as composer would.
        // The names are spelled out rather than read from
        // BundleExclusions::REQUIRED_DIRECTORIES so that dropping one from
        // that list fails here instead of passing vacuously.
        $missingAtInstallTime = [];

        Process::fake([
            'composer install*' => function () use (&$missingAtInstallTime) {
                foreach ([
                    'bootstrap/cache',
                    'storage/framework/cache',
                    'storage/framework/sessions',
                    'storage/framework/views',
                ] as $dir) {
                    if (! is_dir($this->testProjectPath.'/nativephp/android/laravel/'.$dir)) {
                        $missingAtInstallTime[] = $dir;
                    }
                }

                return Process::result();
            },
            'composer dump-autoload*' => Process::result(),
        ]);

        $this->createAndroidProjectFixture();

        (new ReleaseBuildTester)->testPrepareLaravelBundle();

        Process::assertRan('composer install --no-dev --no-interaction');
        $this->assertSame([], $missingAtInstallTime);
    }

    protected function createAndroidProjectFixture(): void
    {
        File::ensureDirectoryExists($this->testProjectPath.'/app');
        File::ensureDirectoryExists($this->testProjectPath.'/bootstrap/cache');
        File::ensureDirectoryExists($this->testProjectPath.'/vendor/nativephp/mobile/bootstrap/android');
        File::ensureDirectoryExists($this->testProjectPath.'/nativephp/android/app/src/main/assets');

        File::put($this->testProjectPath.'/composer.json', json_encode([
            'name' => 'nativephp/release-build-test',
            'description' => 'Release build regression fixture',
            'require' => new \stdClass,
            'scripts' => [
                'post-autoload-dump' => [
                    '@php -r "if (! is_dir(\'bootstrap/cache\')) { fwrite(STDERR, \'missing bootstrap cache\'); exit(13); }"',
                    '@php -r "if (file_exists(\'bootstrap/cache/services.php\')) { fwrite(STDERR, \'stale bootstrap cache copied\'); exit(14); }"',
                ],
            ],
        ], JSON_PRETTY_PRINT));

        File::put($this->testProjectPath.'/app/Example.php', '<?php');
        File::put($this->testProjectPath.'/app/release-fixture.bin', random_bytes(2048));

        File::put($this->testProjectPath.'/artisan', '#!/usr/bin/env php');
        File::put($this->testProjectPath.'/composer.lock', '{}');

        File::ensureDirectoryExists($this->testProjectPath.'/vendor/acme/pkg/src');
        File::put($this->testProjectPath.'/vendor/acme/pkg/src/Pkg.php', '<?php');
        File::put($this->testProjectPath.'/vendor/acme/pkg/README.md', '# strip');

        File::ensureDirectoryExists($this->testProjectPath.'/secret');
        File::put($this->testProjectPath.'/secret/api-key.txt', 'excluded via config');
        File::put($this->testProjectPath.'/bootstrap/cache/packages.php', '<?php return [];');
        File::put($this->testProjectPath.'/bootstrap/cache/services.php', '<?php return [];');
        File::put($this->testProjectPath.'/vendor/nativephp/mobile/bootstrap/android/artisan.php', '<?php // artisan');
    }
}

class ReleaseBuildTester
{
    use PreparesBuild {
        prepareLaravelBundle as public testPrepareLaravelBundle;
    }

    public object $components;

    public function __construct()
    {
        $this->components = new class
        {
            public function task(string $title, callable $callback): mixed
            {
                return $callback();
            }

            public function twoColumnDetail(...$args): void {}

            public function warn(...$args): void {}
        };
    }

    protected function logToFile(string $message): void {}

    protected function info($message): void {}

    protected function warn($message): void {}

    protected function error($message): void {}

    protected function line($message): void {}

    protected function newLine(): void {}

    protected function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    protected function detectCurrentAppId(): ?string
    {
        return null;
    }

    protected function updateAppId(string $oldAppId, string $newAppId): void {}

    protected function updateLocalProperties(): void {}

    protected function updateVersionConfiguration(): void {}

    protected function updateAppDisplayName(): void {}

    protected function updateDeepLinkConfiguration(): void {}

    protected function updatePermissions(): void {}

    protected function updateReleaseAudience(): void {}

    protected function updateIcuConfiguration(): void {}
}
