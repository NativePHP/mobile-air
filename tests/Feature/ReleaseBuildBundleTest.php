<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use Native\Mobile\Traits\PreparesBuild;
use ReflectionClass;
use Tests\TestCase;
use ZipArchive;

class ReleaseBuildBundleTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_release_bundle_test_'.uniqid();

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

        $command = new BuildIosAppCommand;
        $this->setPrivateProperty($command, 'appPath', $this->testProjectPath.'/nativephp/ios/laravel/');

        $this->invokePrivateMethod($command, 'copyLaravelAppIntoIosApp');

        $this->assertFileExists($this->testProjectPath.'/nativephp/ios/laravel/app/Example.php');
        $this->assertDirectoryExists($this->testProjectPath.'/nativephp/ios/laravel/bootstrap/cache');
        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/ios/laravel/bootstrap/cache/packages.php');
        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/ios/laravel/bootstrap/cache/services.php');
    }

    public function test_ios_composer_install_result_is_checked(): void
    {
        $contents = File::get(__DIR__.'/../../src/Commands/BuildIosAppCommand.php');

        $this->assertStringContainsString('$result = Process::path($this->appPath)', $contents);
        $this->assertStringContainsString('if (! $result->successful())', $contents);
        $this->assertStringContainsString('ERROR: composer install failed with exit code ', $contents);
    }

    public function test_android_release_bundle_excludes_cached_bootstrap_files_and_recreates_cache_directory(): void
    {
        $this->createAndroidProjectFixture();

        $builder = new ReleaseBuildTester;
        $builder->testPrepareLaravelBundle();

        $zipPath = $this->testProjectPath.'/nativephp/android/app/src/main/assets/laravel_bundle.zip';
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        $this->assertFalse($zip->statName('bootstrap/cache/services.php'));
        $this->assertFalse($zip->statName('bootstrap/cache/packages.php'));
        $this->assertNotFalse($zip->statName('bootstrap/cache/'));

        $zip->close();
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
        File::put($this->testProjectPath.'/bootstrap/cache/packages.php', '<?php return [];');
        File::put($this->testProjectPath.'/bootstrap/cache/services.php', '<?php return [];');
        File::put($this->testProjectPath.'/vendor/nativephp/mobile/bootstrap/android/artisan.php', '<?php // artisan');
    }

    protected function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $reflection->getProperty($property)->setValue($object, $value);
    }

    protected function invokePrivateMethod(object $object, string $method): mixed
    {
        $reflection = new ReflectionClass($object);

        return $reflection->getMethod($method)->invoke($object);
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

    protected function platformOptimizedCopy(string $source, string $destination, array $excludedDirs): void
    {
        $source = rtrim(str_replace('\\', '/', realpath($source)), '/').'/';

        foreach (File::allFiles($source) as $file) {
            $filePath = str_replace('\\', '/', $file->getRealPath());
            $relativePath = ltrim(substr($filePath, strlen($source)), '/');

            foreach ($excludedDirs as $excludedDir) {
                $excludedDir = rtrim($excludedDir, '/');

                if ($relativePath === $excludedDir || str_starts_with($relativePath, $excludedDir.'/')) {
                    continue 2;
                }
            }

            File::ensureDirectoryExists(dirname($destination.'/'.$relativePath));
            File::copy($filePath, $destination.'/'.$relativePath);
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

    protected function updateIcuConfiguration(): void {}

    protected function updateFirebaseConfiguration(): void {}
}
