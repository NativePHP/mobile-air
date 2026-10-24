<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Commands\RunCommand;
use Native\Mobile\Support\InstalledBinaries;

/*
 * The --json forms of native:run, native:status, native:devices and
 * native:tail are what an agent reads, so their shape is pinned here. Builds
 * are stubbed out and every device command is faked.
 */

beforeEach(function () {
    $this->projectPath = sys_get_temp_dir().'/nativephp_headless_test_'.uniqid();
    File::ensureDirectoryExists($this->projectPath.'/nativephp/android');
    InstalledBinaries::record($this->projectPath.'/nativephp/android');
    app()->setBasePath($this->projectPath);

    config()->set('nativephp.app_id', 'com.example.headless');

    JsonRunCommandStub::$outcome = 'ok';
    JsonInstallCommandFake::$calls = 0;

    $kernel = $this->app->make(Kernel::class);
    $kernel->registerCommand(new JsonRunCommandStub);
    $kernel->registerCommand(new JsonInstallCommandFake);
});

afterEach(function () {
    File::deleteDirectory($this->projectPath);
});

/** Run a command and decode the JSON object on the last line of its output. */
function lastJsonLine(string $command, array $parameters): array
{
    $exitCode = Artisan::call($command, $parameters);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    return [json_decode(end($lines), true), $exitCode];
}

function fakeAndroidApp(string $pid = '4321'): void
{
    Process::fake([
        '*pm*path*' => Process::result("package:/data/app/base.apk\n"),
        '*pidof*' => Process::result($pid === '' ? '' : $pid."\n"),
        'adb devices' => Process::result("List of devices attached\nemulator-5554\tdevice\n\n"),
    ]);
}

describe('native:run --json', function () {
    it('refuses --watch, which never exits', function () {
        [$result, $exitCode] = lastJsonLine('native:run', ['os' => 'android', '--json' => true, '--watch' => true]);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'validate', 'error' => 'watch_not_supported'])
            ->and($exitCode)->toBe(1);
    });

    it('reports a missing app id instead of exiting', function () {
        config()->set('nativephp.app_id', '');

        [$result, $exitCode] = lastJsonLine('native:run', ['os' => 'android', '--json' => true]);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'validate', 'error' => 'missing_app_id'])
            ->and($exitCode)->toBe(1);
    });

    it('asks for the platform rather than prompting when both are installed', function () {
        File::ensureDirectoryExists($this->projectPath.'/nativephp/ios');

        [$result] = lastJsonLine('native:run', ['--json' => true]);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'validate', 'error' => 'ambiguous_platform']);
    })->skip(PHP_OS_FAMILY !== 'Darwin', 'Only macOS can build both platforms.');

    it('rejects a build type the platform does not have', function () {
        [$result] = lastJsonLine('native:run', ['os' => 'android', 'udid' => 'emulator-5554', '--json' => true, '--build' => 'nonsense']);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'validate', 'error' => 'invalid_build_type', 'detail' => 'nonsense'])
            ->and($result['hint'])->toContain('debug, release, bundle, profileable');
    });

    it('fails on stale binaries instead of asking to reinstall', function () {
        File::put(
            $this->projectPath.'/nativephp/android/'.InstalledBinaries::FILE,
            json_encode(['release' => '3.1.0', 'branch' => 'main'])
        );

        // Without interaction a prompt would take its default and reinstall,
        // where an interactive one would hang the agent waiting on it.
        [$result, $exitCode] = lastJsonLine('native:run', ['os' => 'android', 'udid' => 'emulator-5554', '--json' => true, '--no-interaction' => true]);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'validate'])
            ->and($result['error'])->toContain('PHP binaries release 3.1.0')
            ->and($result['hint'])->toContain('native:install android --force')
            ->and($exitCode)->toBe(1)
            ->and(JsonInstallCommandFake::$calls)->toBe(0)
            ->and(JsonRunCommandStub::$built)->toBeFalse();
    });

    it('reports when there is no device to run on', function () {
        Process::fake(['adb devices' => Process::result("List of devices attached\n\n")]);

        [$result] = lastJsonLine('native:run', ['os' => 'android', '--json' => true]);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'devices', 'error' => 'no_device', 'platform' => 'android']);
    });

    it('reports a launched app with its pid', function () {
        fakeAndroidApp();

        [$result, $exitCode] = lastJsonLine('native:run', ['os' => 'android', '--json' => true]);

        expect($result)->toMatchArray([
            'ok' => true,
            'platform' => 'android',
            'device' => 'emulator-5554',
            'appId' => 'com.example.headless',
            'buildType' => 'debug',
            'pid' => 4321,
        ])
            ->and($result)->toHaveKeys(['buildLog', 'durationMs'])
            ->and($exitCode)->toBe(0);
    });

    it('carries a recorded build failure with the end of the build log', function () {
        JsonRunCommandStub::$outcome = 'build_failed';
        File::put($this->projectPath.'/build.log', "line one\nline two\nBUILD FAILED\n");

        [$result, $exitCode] = lastJsonLine('native:run', ['os' => 'android', 'udid' => 'emulator-5554', '--json' => true]);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'build', 'error' => 'Gradle build failed.'])
            ->and($result['logTail'])->toEndWith('BUILD FAILED')
            ->and($exitCode)->toBe(1);
    });

    it('still fails a run that stopped without saying why', function () {
        JsonRunCommandStub::$outcome = 'stopped';

        [$result, $exitCode] = lastJsonLine('native:run', ['os' => 'android', 'udid' => 'emulator-5554', '--json' => true]);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'build'])
            ->and($exitCode)->toBe(1);
    });

    it('fails verification when the app built but is not running', function () {
        fakeAndroidApp(pid: '');

        [$result, $exitCode] = lastJsonLine('native:run', ['os' => 'android', 'udid' => 'emulator-5554', '--json' => true]);

        expect($result)->toMatchArray(['ok' => false, 'stage' => 'verify', 'error' => 'app_not_running'])
            ->and($result['hint'])->toContain('native:tail --fatals')
            ->and($exitCode)->toBe(1);
    });
});

it('reports status with the log and crash spool locations', function () {
    fakeAndroidApp();

    [$result, $exitCode] = lastJsonLine('native:status', ['os' => 'android', '--device' => 'emulator-5554', '--json' => true]);

    expect($result)->toMatchArray([
        'ok' => true,
        'platform' => 'android',
        'device' => 'emulator-5554',
        'appId' => 'com.example.headless',
        'installed' => true,
        'running' => true,
        'pid' => 4321,
    ])
        ->and($result['logs'])->toHaveKeys(['build', 'laravel', 'fatals'])
        ->and($result['logs']['fatals'])->toEndWith('framework/devtools/spool.jsonl')
        ->and($exitCode)->toBe(0);
});

it('lists android devices', function () {
    fakeAndroidApp();

    [$result] = lastJsonLine('native:devices', ['os' => 'android', '--json' => true]);

    expect($result)->toBe([
        'ok' => true,
        'devices' => [['platform' => 'android', 'udid' => 'emulator-5554', 'kind' => 'emulator', 'booted' => true]],
    ]);
});

describe('native:tail --fatals', function () {
    it('reads crash events off the device and skips a line cut short', function () {
        $event = fn (string $message) => json_encode([
            'v' => 1, 'id' => md5($message), 'ts' => '2026-10-23T10:00:00Z', 'kind' => 'boot_fatal', 'mode' => 'boot',
            'platform' => 'android', 'exception' => ['class' => 'RuntimeException', 'message' => $message, 'file' => '/x.php', 'line' => 1, 'app_frame' => 'app/Screens/Home.php:44'],
        ]);

        Process::fake([
            '*run-as*spool.jsonl*' => Process::result($event('first')."\n".$event('second')."\n".'{"v":1,"id":"tr'),
        ]);

        [$result, $exitCode] = lastJsonLine('native:tail', ['os' => 'android', '--device' => 'emulator-5554', '--fatals' => true, '--json' => true]);

        expect($result['ok'])->toBeTrue()
            ->and($result['path'])->toBe('app_storage/persisted_data/storage/framework/devtools/spool.jsonl')
            ->and(array_column(array_column($result['events'], 'exception'), 'message'))->toBe(['first', 'second'])
            ->and($exitCode)->toBe(0);

        Artisan::call('native:tail', ['os' => 'android', '--device' => 'emulator-5554', '--fatals' => true]);

        expect(Artisan::output())
            ->toContain('boot_fatal  RuntimeException: second')
            ->toContain('at app/Screens/Home.php:44');
    });

    it('treats a missing spool as nothing having crashed', function () {
        Process::fake([
            '*run-as*spool.jsonl*' => Process::result(
                errorOutput: 'tail: app_storage/persisted_data/storage/framework/devtools/spool.jsonl: No such file or directory',
                exitCode: 1,
            ),
        ]);

        [$result, $exitCode] = lastJsonLine('native:tail', ['os' => 'android', '--device' => 'emulator-5554', '--fatals' => true, '--json' => true]);

        expect($result)->toMatchArray(['ok' => true, 'events' => []])
            ->and($exitCode)->toBe(0);
    });

    it('still reports an app it cannot read', function () {
        Process::fake([
            '*run-as*' => Process::result(errorOutput: "run-as: package not debuggable: com.example.headless\n", exitCode: 1),
        ]);

        [$result, $exitCode] = lastJsonLine('native:tail', ['os' => 'android', '--device' => 'emulator-5554', '--fatals' => true, '--json' => true]);

        expect($result)->toMatchArray(['ok' => false, 'error' => 'log_not_found'])
            ->and($exitCode)->toBe(1);
    });
});

class JsonRunCommandStub extends RunCommand
{
    public static string $outcome = 'ok';

    public static bool $built = false;

    public function __construct()
    {
        parent::__construct();

        self::$built = false;
    }

    public function runAndroid(): bool
    {
        self::$built = true;

        return match (self::$outcome) {
            'build_failed' => $this->failBuild(),
            'stopped' => false,
            default => true,
        };
    }

    public function runIos(): bool
    {
        return $this->runAndroid();
    }

    protected function waitForLaunch(): void {}

    private function failBuild(): bool
    {
        $this->failRun('build', 'Gradle build failed.', ['buildLog' => base_path('build.log')]);

        return false;
    }
}

class JsonInstallCommandFake extends Command
{
    public static int $calls = 0;

    protected $signature = 'native:install {platform?} {--force}';

    public function handle(): int
    {
        self::$calls++;

        return 0;
    }
}
