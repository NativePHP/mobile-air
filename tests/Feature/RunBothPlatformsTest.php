<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Commands\RunCommand;
use Tests\TestCase;

/**
 * native:run both stages the app once, then runs `native:run ios` and
 * `native:run android` as parallel child processes pointed at the staged
 * tree. The children are faked here; PlatformBuildPoolTest covers running
 * real processes side by side.
 */
class RunBothPlatformsTest extends TestCase
{
    protected string $root;

    protected const SIMULATOR = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = realpath(sys_get_temp_dir()).'/nativephp_run_both_test_'.uniqid();
        File::ensureDirectoryExists($this->root.'/nativephp/ios');
        File::ensureDirectoryExists($this->root.'/nativephp/android');
        File::ensureDirectoryExists($this->root.'/app');
        File::put($this->root.'/app/Example.php', '<?php');
        File::put($this->root.'/composer.json', '{"name":"test/app"}');
        File::put($this->root.'/.env', "APP_NAME=Test\n");

        app()->setBasePath($this->root);
        config(['nativephp.cleanup_exclude_files' => []]);

        $this->app->make(Kernel::class)->registerCommand(tap(new RunBothProbe, fn ($c) => $c->setLaravel($this->app)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        RunBothProbe::$onMac = true;

        parent::tearDown();
    }

    public function test_it_stages_once_and_builds_both_platforms_in_parallel(): void
    {
        $this->fakeProcesses(iosExit: 0, androidExit: 0);
        $this->createArtifacts();

        $this->artisan('native:run', [
            'os' => 'both',
            '--ios-device' => self::SIMULATOR,
            '--android-device' => 'emulator-5556',
        ])
            ->expectsOutputToContain('ios     │ iOS build output')
            ->expectsOutputToContain('android │ Android build output')
            ->expectsOutputToContain('NativePHP-simulator.app')
            ->expectsOutputToContain('app-debug.apk')
            ->assertSuccessful();

        // composer ran once, in the shared staging directory.
        Process::assertRanTimes(fn ($p) => str_starts_with($this->commandLine($p), 'composer install'), 1);
        Process::assertRan(fn ($p) => $this->commandLine($p) === 'composer install --no-interaction');

        // Each child got its device, the build type and the staged tree.
        foreach (['ios' => self::SIMULATOR, 'android' => 'emulator-5556'] as $platform => $device) {
            Process::assertRan(function ($p) use ($platform, $device) {
                $command = $this->commandLine($p);

                return str_contains($command, "native:run {$platform} {$device}")
                    && str_contains($command, '--build=debug')
                    && str_contains($command, '--staged-bundle='.$this->root.'/nativephp/staging/run-')
                    && str_contains($command, '--no-interaction');
            });
        }

        // The children write their own console output to per-platform logs.
        $this->assertStringContainsString('iOS build output', File::get($this->root.'/nativephp/ios-run.log'));
        $this->assertStringContainsString('Android build output', File::get($this->root.'/nativephp/android-run.log'));

        // The staged tree is gone once both builds are done.
        $this->assertSame([], glob($this->root.'/nativephp/staging/run-*'));
    }

    public function test_a_failure_on_one_platform_fails_the_run_and_says_which(): void
    {
        $this->fakeProcesses(iosExit: 0, androidExit: 1);
        $this->createArtifacts();

        $this->artisan('native:run', [
            'os' => 'both',
            '--ios-device' => self::SIMULATOR,
            '--android-device' => 'emulator-5556',
        ])
            ->expectsOutputToContain('failed (exit 1)')
            ->expectsOutputToContain('android-run.log')
            ->assertFailed();

        // iOS still ran to the end rather than being abandoned.
        Process::assertRan(fn ($p) => str_contains($this->commandLine($p), 'native:run ios'));
        $this->assertSame([], glob($this->root.'/nativephp/staging/run-*'));
    }

    public function test_a_platform_that_exits_cleanly_without_an_artifact_is_a_failure(): void
    {
        $this->fakeProcesses(iosExit: 0, androidExit: 0);

        $this->artisan('native:run', [
            'os' => 'both',
            '--ios-device' => self::SIMULATOR,
            '--android-device' => 'emulator-5556',
        ])
            ->expectsOutputToContain('no build output was found')
            ->assertFailed();
    }

    public function test_a_failed_staging_step_builds_neither_platform(): void
    {
        $this->fakeProcesses(iosExit: 0, androidExit: 0, composerExit: 1);

        $this->artisan('native:run', [
            'os' => 'both',
            '--ios-device' => self::SIMULATOR,
            '--android-device' => 'emulator-5556',
        ])->assertFailed();

        Process::assertNotRan(fn ($p) => str_contains($this->commandLine($p), 'native:run'));
        $this->assertSame([], glob($this->root.'/nativephp/staging/run-*'));
    }

    public function test_it_refuses_off_macos(): void
    {
        RunBothProbe::$onMac = false;
        Process::fake();

        $this->artisan('native:run', ['os' => 'both'])
            ->expectsOutputToContain('needs macOS')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_refuses_build_types_ios_does_not_have(): void
    {
        Process::fake();

        $this->artisan('native:run', ['os' => 'both', '--build' => 'bundle'])
            ->expectsOutputToContain('debug or release')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_refuses_watch(): void
    {
        Process::fake();

        $this->artisan('native:run', ['os' => 'both', '--watch' => true])
            ->expectsOutputToContain('--watch is not supported')
            ->assertFailed();
    }

    protected function fakeProcesses(int $iosExit, int $androidExit, int $composerExit = 0): void
    {
        Process::fake([
            'xcrun xctrace list devices' => Process::result(
                "== Simulators ==\niPhone 16 (18.2) (".self::SIMULATOR.")\n"
            ),
            'composer install*' => Process::result(exitCode: $composerExit),
            'composer dump-autoload*' => Process::result(),
            '*native:run*ios*' => Process::describe()
                ->output("iOS build output\n")
                ->iterations(2)
                ->exitCode($iosExit),
            '*native:run*android*' => Process::describe()
                ->output("Android build output\n")
                ->iterations(3)
                ->exitCode($androidExit),
        ]);
    }

    protected function createArtifacts(): void
    {
        File::ensureDirectoryExists($this->root.'/nativephp/ios/build/Build/Products/Debug-iphonesimulator/NativePHP-simulator.app');
        File::ensureDirectoryExists($this->root.'/nativephp/android/app/build/outputs/apk/debug');
        File::put($this->root.'/nativephp/android/app/build/outputs/apk/debug/app-debug.apk', 'apk');
    }

    protected function commandLine($process): string
    {
        return is_array($process->command) ? implode(' ', $process->command) : $process->command;
    }
}

class RunBothProbe extends RunCommand
{
    public static bool $onMac = true;

    protected function canBuildBothHere(): bool
    {
        return self::$onMac;
    }
}
