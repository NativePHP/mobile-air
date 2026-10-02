<?php

namespace Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Commands\BuildIosAppCommand;
use Native\Mobile\Concerns\PreparesBuild;
use Native\Mobile\Exceptions\BundleStagingFailed;
use Native\Mobile\Support\LaravelBundleStager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use ZipArchive;

/**
 * iOS and Android used to copy the app and run composer install each on
 * their own. They now share one staging step, and each platform archives
 * the staged tree without touching it, so one tree can feed both builds.
 */
class SharedBundleStagingTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = realpath(sys_get_temp_dir()).'/nativephp_shared_stage_test_'.uniqid();
        File::ensureDirectoryExists($this->root);
        app()->setBasePath($this->root);

        config([
            'nativephp.cleanup_exclude_files' => [],
            'nativephp.cleanup_env_keys' => ['*_SECRET'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_stages_in_order_with_dev_dependencies_for_debug(): void
    {
        $ran = $this->fakeComposer();
        $this->createApp();

        (new LaravelBundleStager($this->root, $this->root.'/nativephp/staging/app', includeDevDependencies: true))->stage();

        $this->assertSame([
            'composer install --no-interaction',
            'composer dump-autoload --optimize --classmap-authoritative',
        ], $ran->commands);
    }

    public function test_it_drops_dev_dependencies_for_release(): void
    {
        $ran = $this->fakeComposer();
        $this->createApp();

        (new LaravelBundleStager($this->root, $this->root.'/nativephp/staging/app', includeDevDependencies: false))->stage();

        $this->assertSame('composer install --no-dev --no-interaction', $ran->commands[0]);
    }

    public function test_the_staged_tree_is_cleaned_and_its_env_has_no_secrets(): void
    {
        $this->fakeComposer();
        $this->createApp();

        $path = (new LaravelBundleStager($this->root, $this->root.'/nativephp/staging/app', true))->stage();

        $this->assertFileExists($path.'/app/Example.php');
        $this->assertFileDoesNotExist($path.'/composer.lock');
        $this->assertFileDoesNotExist($path.'/artisan');
        $this->assertDirectoryDoesNotExist($path.'/nativephp');

        $env = File::get($path.'/.env');
        $this->assertStringContainsString('APP_NAME=Test', $env);
        $this->assertStringNotContainsString('STRIPE_SECRET', $env);
        $this->assertStringNotContainsString('# comment', $env);
    }

    public function test_a_failed_composer_install_stops_staging(): void
    {
        $ran = $this->fakeComposer(installExitCode: 2);
        $this->createApp();

        try {
            (new LaravelBundleStager($this->root, $this->root.'/nativephp/staging/app', true))->stage();
            $this->fail('Staging should have thrown');
        } catch (BundleStagingFailed $e) {
            $this->assertStringContainsString('Composer install failed', $e->getMessage());
        }

        $this->assertSame(['composer install --no-interaction'], $ran->commands);
    }

    public function test_android_archives_a_shared_tree_without_changing_it(): void
    {
        $ran = $this->fakeComposer();
        $staged = $this->stageSharedTree();
        $before = $this->snapshot($staged);

        File::ensureDirectoryExists($this->root.'/nativephp/android/app/src/main/assets');
        File::ensureDirectoryExists($this->root.'/vendor/nativephp/mobile/bootstrap/android');
        File::put($this->root.'/vendor/nativephp/mobile/bootstrap/android/artisan.php', '<?php // android artisan');
        $ran->commands = [];

        (new AndroidArchiveProbe)->prepare(true, $staged);

        // No second composer run, and the tree is exactly as staged.
        $this->assertSame([], $ran->commands);
        $this->assertSame($before, $this->snapshot($staged));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->root.'/nativephp/android/app/src/main/assets/laravel_bundle.zip'));
        $this->assertNotFalse($zip->statName('app/Example.php'));
        $this->assertSame('<?php // android artisan', $zip->getFromName('artisan.php'));
        $this->assertSame("1.0.0b1\n", $zip->getFromName('.version'));
        $this->assertStringNotContainsString('ASSET_URL', $zip->getFromName('.env'));
        $zip->close();
    }

    public function test_ios_archives_a_shared_tree_without_changing_it(): void
    {
        if (! $this->hasZipBinary()) {
            $this->markTestSkipped('Needs the zip binary.');
        }

        $this->fakeComposer();
        $staged = $this->stageSharedTree();
        File::put($staged.'/vendor/acme/pkg/.hidden', 'dotfile');
        $before = $this->snapshot($staged);

        $container = $this->root.'/nativephp/ios/NativePHP/';
        File::ensureDirectoryExists($container);

        IosArchiveProbe::archive($this->app, $staged, $container);

        $this->assertSame($before, $this->snapshot($staged));
        $this->assertFileExists($container.'bundled.version');
        $this->assertFileExists($container.'bundle_meta.json');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($container.'app.zip'));
        $this->assertNotFalse($zip->statName('app/Example.php'));
        $this->assertFalse($zip->statName('vendor/acme/pkg/.hidden'));

        // Exactly one .env, carrying iOS's ASSET_URL on top of the cleaned file.
        $envEntries = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $envEntries += $zip->getNameIndex($i) === '.env' ? 1 : 0;
        }
        $this->assertSame(1, $envEntries);
        $this->assertSame("APP_NAME=Test\nASSET_URL=\"/_assets\"", $zip->getFromName('.env'));
        $zip->close();
    }

    public function test_ios_build_uses_a_staged_tree_instead_of_staging_again(): void
    {
        if (! $this->hasZipBinary()) {
            $this->markTestSkipped('Needs the zip binary.');
        }

        $ran = $this->fakeComposer();
        $staged = $this->stageSharedTree();
        $ran->commands = [];

        $container = $this->root.'/nativephp/ios/NativePHP/';
        File::ensureDirectoryExists($container);

        IosArchiveProbe::bundle($this->app, $staged, $container);

        $this->assertSame([], $ran->commands);
        $this->assertDirectoryExists($staged);
        $this->assertFileExists($container.'app.zip');
    }

    protected function stageSharedTree(): string
    {
        $this->createApp();

        $path = (new LaravelBundleStager($this->root, $this->root.'/nativephp/staging/shared', true))->stage();

        // What composer install would have produced.
        File::ensureDirectoryExists($path.'/vendor/acme/pkg/src');
        File::put($path.'/vendor/autoload.php', '<?php');
        File::put($path.'/vendor/acme/pkg/src/Pkg.php', '<?php');

        return $path;
    }

    protected function createApp(): void
    {
        File::ensureDirectoryExists($this->root.'/app');
        File::ensureDirectoryExists($this->root.'/nativephp/android');
        File::put($this->root.'/app/Example.php', '<?php');
        File::put($this->root.'/app/padding.bin', random_bytes(2048));
        File::put($this->root.'/composer.json', '{"name":"test/app"}');
        File::put($this->root.'/composer.lock', '{}');
        File::put($this->root.'/artisan', '#!/usr/bin/env php');
        File::put($this->root.'/.env', "APP_NAME=Test\n# comment\nSTRIPE_SECRET=shh\n");
    }

    /**
     * @return object{commands: array<int, string>}
     */
    protected function fakeComposer(int $installExitCode = 0): object
    {
        $ran = new class
        {
            public array $commands = [];
        };

        Process::fake([
            'composer install*' => function ($process) use ($ran, $installExitCode) {
                $ran->commands[] = $process->command;

                return Process::result(exitCode: $installExitCode);
            },
            'composer dump-autoload*' => function ($process) use ($ran) {
                $ran->commands[] = $process->command;

                return Process::result();
            },
        ]);

        return $ran;
    }

    /**
     * @return array<string, string>
     */
    protected function snapshot(string $path): array
    {
        $files = [];

        foreach (File::allFiles($path, true) as $file) {
            $files[$file->getRelativePathname()] = md5_file($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    protected function hasZipBinary(): bool
    {
        return PHP_OS_FAMILY !== 'Windows' && trim((string) shell_exec('command -v zip')) !== '';
    }
}

class IosArchiveProbe extends BuildIosAppCommand
{
    public static function archive($app, string $staged, string $container): void
    {
        self::probe($app, $container, ['--staged-bundle' => $staged])->createAppZip($staged);
    }

    public static function bundle($app, string $staged, string $container): void
    {
        self::probe($app, $container, ['--staged-bundle' => $staged])->bundleLaravelApp();
    }

    protected static function probe($app, string $container, array $options): self
    {
        $command = new self;
        $command->setLaravel($app);

        $input = new ArrayInput($options, $command->getDefinition());
        $output = new OutputStyle($input, new BufferedOutput);

        $command->input = $input;
        $command->output = $output;
        $command->components = new Factory($output);

        \Closure::bind(function () use ($container) {
            $this->containerPath = $container;
            $this->appPath = dirname($container).'/laravel/';
            $this->logPath = dirname($container).'/ios-build.log';
            $this->verbose = false;
        }, $command, BuildIosAppCommand::class)();

        return $command;
    }
}

class AndroidArchiveProbe
{
    use PreparesBuild {
        prepareLaravelBundle as public prepare;
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
        };
    }

    protected function logToFile(string $message): void {}

    protected function removeDirectory(string $path): void
    {
        File::deleteDirectory($path);
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
