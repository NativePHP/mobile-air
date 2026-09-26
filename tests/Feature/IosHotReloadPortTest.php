<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Native\Mobile\Concerns\RunsIos;
use Native\Mobile\Concerns\WatchesIos;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Every simulator app shares the Mac's network stack, and physical devices
 * are reached through iproxy on a Mac port, so a fixed hot reload port let
 * one app on the whole machine hot reload at a time. `native:run` used to
 * free that port with `lsof | xargs kill -9`, killing whichever app held it,
 * including other developers' apps on other simulators.
 *
 * Now each target gets a free port, recorded per simulator or device UDID,
 * and the watcher sends reloads to the port recorded for its target.
 */
beforeEach(function () {
    $this->projectPath = sys_get_temp_dir().'/nativephp_hot_reload_port_'.uniqid();
    File::makeDirectory($this->projectPath.'/nativephp/ios/NativePHP', 0755, true);
    app()->setBasePath($this->projectPath);

    // The app's side of the contract: the real server source, as
    // `native:install ios` copies it into the project.
    File::copy(
        __DIR__.'/../../resources/xcode/NativePHP/HotReloadServer.swift',
        $this->projectPath.'/nativephp/ios/NativePHP/HotReloadServer.swift',
    );

    Prompt::setOutput(new BufferedOutput);

    // Ports lsof should report a listener on. Everything else is free.
    $this->listening = [];

    Process::fake([
        '*lsof*' => function (PendingProcess $process) {
            foreach ($this->listening as $port) {
                if (in_array("-iTCP:{$port}", (array) $process->command, true)) {
                    return "4242\n";
                }
            }

            return '';
        },
        '*' => '',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->projectPath);
});

/**
 * `native:run ios` from the point the build has finished, on a simulator.
 */
function iosSimulatorRunner(): object
{
    return new class
    {
        use RunsIos;

        public object $components;

        public function __construct()
        {
            $this->iosLogPath = base_path('nativephp/ios-build.log');

            $this->components = new class
            {
                /** @var list<string> */
                public array $tasks = [];

                public function task(string $title, callable $callback): void
                {
                    $this->tasks[] = $title;
                    $callback();
                }
            };
        }

        public function launch(string $target): void
        {
            $this->runOnSimulator(base_path('nativephp/ios'), $target);
        }

        public function recorded(string $target): ?int
        {
            return $this->recordedIosHotReloadPort($target);
        }

        public function option(string $key): mixed
        {
            return false;
        }

        protected function openSimulatorUi(string $udid, bool $selectDevice): void {}
    };
}

function iosPortWatcher(string $target): object
{
    return new class($target)
    {
        use WatchesIos;

        /** @var list<string> */
        public array $lines = [];

        public function __construct(string $target)
        {
            $this->iosTarget = $target;
        }

        public function port(): int
        {
            return $this->iosHotReloadPort();
        }

        public function pick(): int
        {
            return $this->pickIosHotReloadPort((string) $this->iosTarget);
        }

        public function record(int $port): void
        {
            $this->recordIosHotReloadPort((string) $this->iosTarget, $port);
        }

        public function reload(): void
        {
            $this->triggerIosReload();
        }

        public function line(string $string): void
        {
            $this->lines[] = $string;
        }
    };
}

/**
 * The simctl launch of $target, with the environment it was given.
 */
function simctlLaunch(string $target): ?PendingProcess
{
    $launch = null;

    Process::assertRan(function (PendingProcess $process) use ($target, &$launch) {
        if ($process->command === "xcrun simctl launch {$target} com.test.app") {
            $launch = $process;

            return true;
        }

        return false;
    });

    return $launch;
}

it('launches each simulator app with a port of its own', function () {
    $runner = iosSimulatorRunner();

    $runner->launch('SIM-A');
    $portA = $runner->recorded('SIM-A');

    // App A is now listening on its port.
    $this->listening[] = $portA;

    $runner->launch('SIM-B');
    $portB = $runner->recorded('SIM-B');

    expect($portA)->toBeInt()->not->toBe($portB);
    expect(simctlLaunch('SIM-A')->environment)->toBe(['SIMCTL_CHILD_NATIVEPHP_HOT_RELOAD_PORT' => (string) $portA]);
    expect(simctlLaunch('SIM-B')->environment)->toBe(['SIMCTL_CHILD_NATIVEPHP_HOT_RELOAD_PORT' => (string) $portB]);
    expect($runner->components->tasks)->toContain("Launching app (hot reload port {$portB})");
});

it('kills nothing but its own app on its own simulator', function () {
    iosSimulatorRunner()->launch('SIM-A');

    Process::assertRan('xcrun simctl terminate SIM-A com.test.app');
    Process::assertDidntRun(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'kill'));
});

it('keeps the port when the same app is relaunched on the same simulator', function () {
    $runner = iosSimulatorRunner();

    $runner->launch('SIM-A');
    $first = $runner->recorded('SIM-A');

    // simctl terminate stopped the old instance, so its port is free again.
    $runner->launch('SIM-A');

    expect($runner->recorded('SIM-A'))->toBe($first);
});

it('moves a target off its recorded port when something else holds it', function () {
    $watcher = iosPortWatcher('SIM-A');
    $watcher->record(50123);

    expect($watcher->pick())->toBe(50123);

    $this->listening[] = 50123;

    expect($watcher->pick())->toBeGreaterThan(0)->toBeLessThanOrEqual(65535)->not->toBe(50123);
});

it('keeps a separate record for each simulator and device', function () {
    iosPortWatcher('SIM-A')->record(50001);
    iosPortWatcher('00008140-00092D393431801C')->record(50002);

    expect(iosPortWatcher('SIM-A')->port())->toBe(50001);
    expect(iosPortWatcher('00008140-00092D393431801C')->port())->toBe(50002);
    expect(base_path('nativephp/ios-hot-reload-ports/SIM-A'))->toBeFile();
});

it('falls back to the default port for a target with no usable record', function () {
    expect(iosPortWatcher('SIM-A')->port())->toBe(9999);

    File::ensureDirectoryExists(base_path('nativephp/ios-hot-reload-ports'));
    File::put(base_path('nativephp/ios-hot-reload-ports/SIM-A'), 'not a port');

    expect(iosPortWatcher('SIM-A')->port())->toBe(9999);
});

it('sends reloads to the port recorded for the watched target', function () {
    // A real listener on a free port. Never 9999: a simulator app on this
    // machine may be holding it, and the trigger would reload that app.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse($errstr);
    $address = stream_socket_get_name($server, false);
    $port = (int) substr($address, strrpos($address, ':') + 1);

    try {
        iosPortWatcher('SIM-B')->record(50999);
        iosPortWatcher('SIM-A')->record($port);

        $watcher = iosPortWatcher('SIM-A');
        $watcher->reload();

        $connection = stream_socket_accept($server, 1);
        expect($connection)->not->toBeFalse();
        stream_set_timeout($connection, 2);

        expect(stream_get_contents($connection))->toBe("nativephp:hot-reload\n");
        expect($watcher->lines)->toBe([]);

        fclose($connection);
    } finally {
        fclose($server);
    }
});

// An app whose Xcode project was installed before this change ignores the
// launch environment and binds 9999, so the watcher has to aim there too.
it('keeps an older iOS project on the default port', function () {
    File::put(base_path('nativephp/ios/NativePHP/HotReloadServer.swift'), 'private let port: NWEndpoint.Port = 9999');

    $runner = iosSimulatorRunner();
    $runner->launch('SIM-A');

    expect($runner->recorded('SIM-A'))->toBe(9999);
    expect(simctlLaunch('SIM-A')->environment)->toBe(['SIMCTL_CHILD_NATIVEPHP_HOT_RELOAD_PORT' => '9999']);
});

it('reads the port from the key the Swift server looks for', function () {
    $swift = File::get(__DIR__.'/../../resources/xcode/NativePHP/HotReloadServer.swift');

    expect($swift)->toContain('static let portKey = "NATIVEPHP_HOT_RELOAD_PORT"');
});
