<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Native\Mobile\Commands\BuildIosAppCommand;
use Native\Mobile\Concerns\PreparesBuild;
use Native\Mobile\Plugins\Commands\NativePluginHookCommand;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginHookRunner;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use ZipArchive;

/**
 * Covers the prepare_bundle lifecycle hook: it fires exactly once per
 * platform per build, after the staging tree is fully prepared and before
 * version files are written or the archive is created, and — unlike the
 * other four lifecycle hooks — a failure aborts the build instead of only
 * warning.
 */
class PrepareBundleHookTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = realpath(sys_get_temp_dir()).'/nativephp_prepare_bundle_test_'.uniqid();
        File::ensureDirectoryExists($this->testProjectPath);
        app()->setBasePath($this->testProjectPath);

        config([
            'nativephp.cleanup_exclude_files' => [],
            'nativephp.runtime.mode' => 'persistent',
        ]);

        $kernel = $this->app[Kernel::class];
        $kernel->registerCommand(new MarkerPrepareBundleHookCommand);
        $kernel->registerCommand(new NonZeroExitPrepareBundleHookCommand);
        $kernel->registerCommand(new ThrowingPrepareBundleHookCommand);
        $kernel->registerCommand(new NonZeroExitLegacyHookCommand);
        $kernel->registerCommand(new ThrowingLegacyHookCommand);

        MarkerPrepareBundleHookCommand::reset();
        NonZeroExitLegacyHookCommand::reset();
        ThrowingLegacyHookCommand::reset();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        Mockery::close();

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // PluginHookRunner — isolated from the build pipeline
    // -------------------------------------------------------------------

    public function test_prepare_bundle_hook_receives_bundle_path_and_normalised_config(): void
    {
        $plugin = $this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-writes-marker');
        $bundlePath = $this->testProjectPath.'/staged-bundle';
        File::ensureDirectoryExists($bundlePath);

        $runner = new PluginHookRunner(
            platform: 'android',
            buildPath: $this->testProjectPath.'/nativephp/android',
            appId: 'com.test.app',
            config: [
                'version' => '1.2.3',
                'version_code' => 9,
                'build_type' => 'release',
                'release' => true,
                'bundle_version_id' => '1.2.3b9',
            ],
            plugins: collect([$plugin]),
        );

        $runner->runPrepareBundleHooks($bundlePath);

        $this->assertSame(1, MarkerPrepareBundleHookCommand::$callCount);
        $this->assertSame('android', MarkerPrepareBundleHookCommand::$lastPlatform);
        $this->assertSame($this->testProjectPath.'/nativephp/android', MarkerPrepareBundleHookCommand::$lastBuildPath);
        $this->assertSame($bundlePath, MarkerPrepareBundleHookCommand::$lastBundlePath);
        $this->assertSame('release', MarkerPrepareBundleHookCommand::$lastConfig['build_type']);
        $this->assertTrue(MarkerPrepareBundleHookCommand::$lastConfig['release']);
        $this->assertSame('1.2.3b9', MarkerPrepareBundleHookCommand::$lastConfig['bundle_version_id']);
        $this->assertFileExists($bundlePath.'/hook-marker.txt');
    }

    public function test_prepare_bundle_hook_nonzero_exit_code_aborts(): void
    {
        $plugin = $this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-nonzero-exit');
        $bundlePath = $this->testProjectPath.'/staged-bundle-2';
        File::ensureDirectoryExists($bundlePath);

        $runner = new PluginHookRunner('android', $this->testProjectPath.'/nativephp/android', 'com.test.app', [], collect([$plugin]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-zero exit code: 7');

        $runner->runPrepareBundleHooks($bundlePath);
    }

    public function test_prepare_bundle_hook_thrown_exception_aborts(): void
    {
        $plugin = $this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-throws');
        $bundlePath = $this->testProjectPath.'/staged-bundle-3';
        File::ensureDirectoryExists($bundlePath);

        $runner = new PluginHookRunner('android', $this->testProjectPath.'/nativephp/android', 'com.test.app', [], collect([$plugin]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prepare_bundle hook exploded');

        $runner->runPrepareBundleHooks($bundlePath);
    }

    public function test_existing_hooks_still_only_warn_on_nonzero_exit_code(): void
    {
        $plugin = $this->pluginWithHook('post_compile', 'test:legacy-hook-nonzero-exit');
        $runner = new PluginHookRunner('android', $this->testProjectPath.'/nativephp/android', 'com.test.app', [], collect([$plugin]));

        // No exception propagates — the legacy hook path only warns on failure.
        $runner->runPostCompileHooks();

        $this->assertSame(1, NonZeroExitLegacyHookCommand::$callCount);
    }

    public function test_existing_hooks_still_only_warn_on_thrown_exception(): void
    {
        $plugin = $this->pluginWithHook('post_compile', 'test:legacy-hook-throws');
        $runner = new PluginHookRunner('android', $this->testProjectPath.'/nativephp/android', 'com.test.app', [], collect([$plugin]));

        $runner->runPostCompileHooks();

        $this->assertSame(1, ThrowingLegacyHookCommand::$callCount);
    }

    // -------------------------------------------------------------------
    // NativePluginHookCommand accessors
    // -------------------------------------------------------------------

    public function test_hook_command_accessors_read_bundle_path_build_type_and_release(): void
    {
        $command = new MarkerPrepareBundleHookCommand;
        $this->bindCommandInput($command, [
            '--bundle-path' => '/tmp/bundle',
            '--config' => json_encode(['build_type' => 'release', 'release' => true]),
        ]);

        $this->assertSame('/tmp/bundle', $this->invokeProtected($command, 'bundlePath'));
        $this->assertSame('release', $this->invokeProtected($command, 'buildType'));
        $this->assertTrue($this->invokeProtected($command, 'isRelease'));
    }

    public function test_hook_command_accessors_default_when_absent(): void
    {
        $command = new MarkerPrepareBundleHookCommand;
        $this->bindCommandInput($command, []);

        $this->assertSame('', $this->invokeProtected($command, 'bundlePath'));
        $this->assertSame('debug', $this->invokeProtected($command, 'buildType'));
        $this->assertFalse($this->invokeProtected($command, 'isRelease'));
    }

    // -------------------------------------------------------------------
    // Android pipeline (PreparesBuild::prepareLaravelBundle())
    // -------------------------------------------------------------------

    public function test_android_hook_runs_once_before_version_write_and_output_lands_in_zip(): void
    {
        Process::fake([
            'composer install*' => Process::result(),
            'composer dump-autoload*' => Process::result(),
        ]);

        $this->createAndroidProjectFixture();
        $this->bindRegistryWithPlugins([$this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-writes-marker')]);

        (new PrepareBundleAndroidTester)->testPrepareLaravelBundle(true, 'release');

        $this->assertSame(1, MarkerPrepareBundleHookCommand::$callCount);
        $this->assertSame('android', MarkerPrepareBundleHookCommand::$lastPlatform);
        $this->assertFalse(MarkerPrepareBundleHookCommand::$versionMarkerExistedAtCallTime);
        $this->assertSame('release', MarkerPrepareBundleHookCommand::$lastConfig['build_type']);
        $this->assertTrue(MarkerPrepareBundleHookCommand::$lastConfig['release']);
        $this->assertNotEmpty(MarkerPrepareBundleHookCommand::$lastConfig['bundle_version_id']);

        $zipPath = $this->testProjectPath.'/nativephp/android/app/src/main/assets/laravel_bundle.zip';
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $this->assertNotFalse($zip->statName('hook-marker.txt'));
        $this->assertFalse($zip->statName('app/Example.php'));
        $zip->close();
    }

    public function test_android_hook_nonzero_exit_code_aborts_before_zip_and_cleans_staging(): void
    {
        Process::fake([
            'composer install*' => Process::result(),
            'composer dump-autoload*' => Process::result(),
        ]);

        $this->createAndroidProjectFixture();
        $this->bindRegistryWithPlugins([$this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-nonzero-exit')]);

        try {
            (new PrepareBundleAndroidTester)->testPrepareLaravelBundle(true, 'release');
            $this->fail('Expected the failing prepare_bundle hook to abort the build.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('prepare_bundle', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/android/app/src/main/assets/laravel_bundle.zip');
        $this->assertDirectoryDoesNotExist($this->testProjectPath.'/nativephp/android/laravel');
    }

    public function test_android_hook_thrown_exception_aborts_and_cleans_staging(): void
    {
        Process::fake([
            'composer install*' => Process::result(),
            'composer dump-autoload*' => Process::result(),
        ]);

        $this->createAndroidProjectFixture();
        $this->bindRegistryWithPlugins([$this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-throws')]);

        try {
            (new PrepareBundleAndroidTester)->testPrepareLaravelBundle(true, 'release');
            $this->fail('Expected the throwing prepare_bundle hook to abort the build.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('prepare_bundle hook exploded', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/android/app/src/main/assets/laravel_bundle.zip');
        $this->assertDirectoryDoesNotExist($this->testProjectPath.'/nativephp/android/laravel');
    }

    public function test_android_build_succeeds_unchanged_with_no_registered_plugins(): void
    {
        Process::fake([
            'composer install*' => Process::result(),
            'composer dump-autoload*' => Process::result(),
        ]);

        $this->createAndroidProjectFixture();
        $this->bindRegistryWithPlugins([]);

        (new PrepareBundleAndroidTester)->testPrepareLaravelBundle(true, 'release');

        $this->assertSame(0, MarkerPrepareBundleHookCommand::$callCount);
        $this->assertFileExists($this->testProjectPath.'/nativephp/android/app/src/main/assets/laravel_bundle.zip');
    }

    // -------------------------------------------------------------------
    // iOS pipeline (BuildIosAppCommand::bundleLaravelApp())
    //
    // bundleLaravelApp() is private and is the very first statement in
    // handle(), reached identically on CLI builds and on the Xcode build
    // phase (NATIVEPHP_XCODE_BUILD=1) before either branches — reflection
    // invokes it directly, which also sidesteps handle()'s macOS-only gate
    // so this runs on the Linux CI runner. The `zip` binary itself isn't
    // available in this environment, so Process is faked wholesale
    // (composer install and the shell `zip`/`rm -rf` calls); the pipeline's
    // in-place file mutations are asserted directly on the staging tree.
    // -------------------------------------------------------------------

    public function test_ios_hook_runs_once_before_bundled_version_write_and_mutates_staged_tree(): void
    {
        Process::fake();
        $this->createIosProjectFixture();
        $this->bindRegistryWithPlugins([$this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-writes-marker')]);

        $command = $this->makeIosCommand(release: true);
        $this->invokeBundleLaravelApp($command);

        $this->assertSame(1, MarkerPrepareBundleHookCommand::$callCount);
        $this->assertSame('ios', MarkerPrepareBundleHookCommand::$lastPlatform);
        $this->assertFalse(MarkerPrepareBundleHookCommand::$versionMarkerExistedAtCallTime);
        $this->assertSame('release', MarkerPrepareBundleHookCommand::$lastConfig['build_type']);
        $this->assertTrue(MarkerPrepareBundleHookCommand::$lastConfig['release']);

        $basePath = $this->testProjectPath.'/nativephp/ios';
        $this->assertFileExists($basePath.'/laravel/hook-marker.txt');
        $this->assertFileDoesNotExist($basePath.'/laravel/app/Example.php');
        $this->assertFileExists($basePath.'/NativePHP/bundled.version');
    }

    public function test_ios_hook_runs_regardless_of_xcode_build_phase_env(): void
    {
        putenv('NATIVEPHP_XCODE_BUILD=1');

        try {
            Process::fake();
            $this->createIosProjectFixture();
            $this->bindRegistryWithPlugins([$this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-writes-marker')]);

            $command = $this->makeIosCommand(release: false);
            $this->invokeBundleLaravelApp($command);

            $this->assertSame(1, MarkerPrepareBundleHookCommand::$callCount);
            $this->assertSame('debug', MarkerPrepareBundleHookCommand::$lastConfig['build_type']);
            $this->assertFalse(MarkerPrepareBundleHookCommand::$lastConfig['release']);
        } finally {
            putenv('NATIVEPHP_XCODE_BUILD');
        }
    }

    public function test_ios_hook_nonzero_exit_code_aborts_before_zip(): void
    {
        Process::fake();
        $this->createIosProjectFixture();
        $this->bindRegistryWithPlugins([$this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-nonzero-exit')]);

        $command = $this->makeIosCommand(release: true);

        try {
            $this->invokeBundleLaravelApp($command);
            $this->fail('Expected the failing prepare_bundle hook to abort the build.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('prepare_bundle', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/ios/NativePHP/bundled.version');
    }

    public function test_ios_hook_thrown_exception_aborts_before_zip(): void
    {
        Process::fake();
        $this->createIosProjectFixture();
        $this->bindRegistryWithPlugins([$this->pluginWithHook('prepare_bundle', 'test:prepare-bundle-throws')]);

        $command = $this->makeIosCommand(release: true);

        try {
            $this->invokeBundleLaravelApp($command);
            $this->fail('Expected the throwing prepare_bundle hook to abort the build.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('prepare_bundle hook exploded', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/ios/NativePHP/bundled.version');
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    protected function pluginWithHook(string $hookName, string $signature): Plugin
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
            'hooks' => [$hookName => $signature],
        ]);

        return new Plugin(
            name: 'test/prepare-bundle-plugin',
            version: '1.0.0',
            path: $this->testProjectPath.'/plugins/test-plugin',
            manifest: $manifest,
        );
    }

    protected function bindRegistryWithPlugins(array $plugins): void
    {
        $registry = Mockery::mock(PluginRegistry::class);
        $registry->shouldReceive('count')->andReturn(count($plugins));

        if (count($plugins) > 0) {
            $registry->shouldReceive('all')->andReturn(collect($plugins));
        }

        $this->app->instance(PluginRegistry::class, $registry);
    }

    protected function bindCommandInput(NativePluginHookCommand $command, array $options): void
    {
        $input = new ArrayInput($options, $command->getDefinition());

        $property = (new ReflectionClass($command))->getProperty('input');
        $property->setAccessible(true);
        $property->setValue($command, $input);
    }

    protected function invokeProtected(object $object, string $method, array $args = []): mixed
    {
        $reflected = (new ReflectionClass($object))->getMethod($method);
        $reflected->setAccessible(true);

        return $reflected->invoke($object, ...$args);
    }

    protected function createAndroidProjectFixture(): void
    {
        File::ensureDirectoryExists($this->testProjectPath.'/app');
        File::ensureDirectoryExists($this->testProjectPath.'/bootstrap/cache');
        File::ensureDirectoryExists($this->testProjectPath.'/vendor/nativephp/mobile/bootstrap/android');
        File::ensureDirectoryExists($this->testProjectPath.'/nativephp/android/app/src/main/assets');

        File::put($this->testProjectPath.'/composer.json', json_encode([
            'name' => 'nativephp/prepare-bundle-hook-test',
            'require' => new \stdClass,
        ], JSON_PRETTY_PRINT));

        File::put($this->testProjectPath.'/app/Example.php', '<?php');
        File::put($this->testProjectPath.'/artisan', '#!/usr/bin/env php');
        File::put($this->testProjectPath.'/composer.lock', '{}');
        File::put($this->testProjectPath.'/bootstrap/cache/services.php', '<?php return [];');
        File::put($this->testProjectPath.'/vendor/nativephp/mobile/bootstrap/android/artisan.php', '<?php // artisan');
    }

    protected function createIosProjectFixture(): void
    {
        File::ensureDirectoryExists($this->testProjectPath.'/app');
        File::put($this->testProjectPath.'/app/Example.php', '<?php');

        $basePath = $this->testProjectPath.'/nativephp/ios';
        File::ensureDirectoryExists($basePath.'/NativePHP');
    }

    protected function makeIosCommand(bool $release): BuildIosAppCommand
    {
        $command = new BuildIosAppCommand;
        $command->setLaravel($this->app);

        $reflection = new ReflectionClass($command);

        $setProperty = function (string $name, mixed $value) use ($reflection, $command) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($command, $value);
        };

        $setProperty('input', new ArrayInput(['--release' => $release], $command->getDefinition()));
        $setProperty('output', new BufferedOutput);
        $setProperty('verbose', false);
        $setProperty('components', new class
        {
            public function task(string $title, \Closure $callback): bool
            {
                return (bool) $callback();
            }

            public function twoColumnDetail(...$args): void {}
        });

        $basePath = $this->testProjectPath.'/nativephp/ios';
        $setProperty('basePath', $basePath);
        $setProperty('containerPath', $basePath.'/NativePHP/');
        $setProperty('appPath', $basePath.'/laravel/');
        $setProperty('logPath', $this->testProjectPath.'/nativephp/ios-build.log');

        return $command;
    }

    protected function invokeBundleLaravelApp(BuildIosAppCommand $command): void
    {
        $method = (new ReflectionClass($command))->getMethod('bundleLaravelApp');
        $method->setAccessible(true);
        $method->invoke($command);
    }
}

/**
 * Exposes PreparesBuild::prepareLaravelBundle() the way ReleaseBuildBundleTest's
 * ReleaseBuildTester does, so the Android staging pipeline can be driven
 * without a full Command/Kernel round trip.
 */
class PrepareBundleAndroidTester
{
    use PreparesBuild {
        prepareLaravelBundle as public testPrepareLaravelBundle;
    }

    public ?object $output = null;

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

    protected function updateIcuConfiguration(): void {}

    protected function updateFirebaseConfiguration(): void {}
}

class MarkerPrepareBundleHookCommand extends NativePluginHookCommand
{
    protected $signature = 'test:prepare-bundle-writes-marker';

    public static int $callCount = 0;

    public static array $lastConfig = [];

    public static ?string $lastPlatform = null;

    public static ?string $lastBuildPath = null;

    public static ?string $lastBundlePath = null;

    public static bool $versionMarkerExistedAtCallTime = false;

    public static function reset(): void
    {
        self::$callCount = 0;
        self::$lastConfig = [];
        self::$lastPlatform = null;
        self::$lastBuildPath = null;
        self::$lastBundlePath = null;
        self::$versionMarkerExistedAtCallTime = false;
    }

    public function handle(): int
    {
        self::$callCount++;
        self::$lastConfig = $this->config();
        self::$lastPlatform = $this->platform();
        self::$lastBuildPath = $this->buildPath();
        self::$lastBundlePath = $this->bundlePath();

        // Android writes .version inside the staged tree; iOS writes
        // bundled.version next to the container path. Whichever this
        // platform uses, it must not exist yet when the hook runs.
        self::$versionMarkerExistedAtCallTime =
            file_exists($this->bundlePath().'/.version')
            || file_exists($this->buildPath().'/NativePHP/bundled.version');

        file_put_contents($this->bundlePath().'/hook-marker.txt', 'transformed-by-plugin');
        @unlink($this->bundlePath().'/app/Example.php');

        return self::SUCCESS;
    }
}

class NonZeroExitPrepareBundleHookCommand extends NativePluginHookCommand
{
    protected $signature = 'test:prepare-bundle-nonzero-exit';

    public function handle(): int
    {
        return 7;
    }
}

class ThrowingPrepareBundleHookCommand extends NativePluginHookCommand
{
    protected $signature = 'test:prepare-bundle-throws';

    public function handle(): int
    {
        throw new RuntimeException('prepare_bundle hook exploded');
    }
}

class NonZeroExitLegacyHookCommand extends NativePluginHookCommand
{
    protected $signature = 'test:legacy-hook-nonzero-exit';

    public static int $callCount = 0;

    public static function reset(): void
    {
        self::$callCount = 0;
    }

    public function handle(): int
    {
        self::$callCount++;

        return 3;
    }
}

class ThrowingLegacyHookCommand extends NativePluginHookCommand
{
    protected $signature = 'test:legacy-hook-throws';

    public static int $callCount = 0;

    public static function reset(): void
    {
        self::$callCount = 0;
    }

    public function handle(): int
    {
        self::$callCount++;

        throw new RuntimeException('legacy hook exploded');
    }
}
