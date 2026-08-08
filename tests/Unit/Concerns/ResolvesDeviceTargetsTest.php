<?php

use Illuminate\Support\Facades\Process;
use Native\Mobile\Concerns\ResolvesDeviceTargets;

function deviceResolver(): object
{
    return new class
    {
        use ResolvesDeviceTargets;

        public array $options = [];

        public function option($key)
        {
            return $this->options[$key] ?? null;
        }

        public function resolve(?string $platform, ?string $explicit): array
        {
            return $this->resolveDeviceTarget($platform, $explicit);
        }

        public function androidDevices(): array
        {
            return $this->listAndroidDevices();
        }
    };
}

beforeEach(function () {
    $this->claimPath = base_path('nativephp/agent-device.json');

    if (! is_dir(base_path('nativephp'))) {
        mkdir(base_path('nativephp'), 0755, true);
    }

    @unlink($this->claimPath);
});

afterEach(function () {
    @unlink($this->claimPath);
});

it('uses an explicit device without touching device tooling', function () {
    $result = deviceResolver()->resolve('ios', 'SOME-UDID');

    expect($result)->toBe(['ok' => true, 'platform' => 'ios', 'udid' => 'SOME-UDID']);
});

it('rejects an invalid platform', function () {
    $result = deviceResolver()->resolve('windows', null);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('invalid_platform');
});

it('honours an agent device claim', function () {
    file_put_contents($this->claimPath, json_encode([
        'platform' => 'android',
        'udid' => 'emulator-5554',
    ]));

    $result = deviceResolver()->resolve(null, null);

    expect($result)->toBe(['ok' => true, 'platform' => 'android', 'udid' => 'emulator-5554']);
});

it('ignores a claim for a different platform than requested', function () {
    file_put_contents($this->claimPath, json_encode([
        'platform' => 'android',
        'udid' => 'emulator-5554',
    ]));

    Process::fake([
        'adb devices' => Process::result("List of devices attached\n"),
    ]);

    $result = deviceResolver()->resolve('android', 'explicit-serial');

    expect($result['udid'])->toBe('explicit-serial');
});

it('parses adb devices output into serial/kind pairs', function () {
    Process::fake([
        'adb devices' => Process::result(
            "List of devices attached\nemulator-5554\tdevice\nR58M123ABC\tdevice\nunauth-1\tunauthorized\n"
        ),
    ]);

    $devices = deviceResolver()->androidDevices();

    expect($devices)->toHaveCount(2)
        ->and($devices[0])->toMatchArray(['udid' => 'emulator-5554', 'kind' => 'emulator'])
        ->and($devices[1])->toMatchArray(['udid' => 'R58M123ABC', 'kind' => 'device']);
});

it('returns a structured no_device error when nothing is connected', function () {
    Process::fake([
        'adb devices' => Process::result("List of devices attached\n"),
    ]);

    $result = deviceResolver()->resolve('android', null);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('no_device')
        ->and($result['hint'])->not->toBeEmpty();
});

it('resolves the single connected android device', function () {
    Process::fake([
        'adb devices' => Process::result("List of devices attached\nemulator-5554\tdevice\n"),
    ]);

    $result = deviceResolver()->resolve('android', null);

    expect($result['ok'])->toBeTrue()
        ->and($result['udid'])->toBe('emulator-5554')
        ->and($result['kind'])->toBe('emulator');
});
