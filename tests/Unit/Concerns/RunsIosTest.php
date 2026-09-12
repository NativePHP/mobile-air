<?php

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use Native\Mobile\Concerns\RunsIos;
use Orchestra\Testbench\TestCase;

class RunsIosTest extends TestCase
{
    use RunsIos;

    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_ios_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        app()->setBasePath($this->testProjectPath);

        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);

        parent::tearDown();
    }

    public function test_returns_the_single_filtered_device_without_prompting()
    {
        $devices = [
            [
                'name' => 'iPhone 17 Pro',
                'version' => '18.6',
                'udid' => 'FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6',
                'category' => 'Simulators',
            ],
        ];

        $target = $this->promptForIosTarget($devices);

        $this->assertSame('FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6', $target);
    }

    public function test_prompts_when_multiple_devices_are_available()
    {
        $devices = [
            [
                'name' => 'iPhone 17 Pro',
                'version' => '18.6',
                'udid' => 'FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6',
                'category' => 'Simulators',
            ],
            [
                'name' => 'iPhone 17',
                'version' => '18.6',
                'udid' => '0D25A1A9-959C-404C-8E40-7863A7AF508F',
                'category' => 'Simulators',
            ],
        ];

        // With more than one candidate, the trait still asks — no attached
        // terminal here, so Prompts fails the "Required" validation instead
        // of silently guessing, proving the single-device shortcut was not
        // (wrongly) taken.
        $this->expectException(NonInteractiveValidationException::class);

        $this->promptForIosTarget($devices);
    }

    public function test_resolve_default_target_prefers_the_one_booted_simulator_over_prompting()
    {
        // Two installed simulators would normally force a prompt, but only
        // one of them is actually booted.
        $devices = [
            [
                'name' => 'iPhone 17 Pro',
                'version' => '26.5',
                'udid' => 'FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6',
                'category' => 'Simulators',
            ],
            [
                'name' => 'iPhone 17',
                'version' => '26.5',
                'udid' => '0D25A1A9-959C-404C-8E40-7863A7AF508F',
                'category' => 'Simulators',
            ],
        ];

        Process::fake([
            '*simctl*list*devices*booted*' => Process::result(json_encode([
                'devices' => [
                    'com.apple.CoreSimulator.SimRuntime.iOS-26-5' => [
                        ['udid' => 'FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6', 'state' => 'Booted'],
                    ],
                ],
            ])),
        ]);

        $target = $this->resolveDefaultIosTarget($devices);

        $this->assertSame('FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6', $target);
    }

    public function test_resolve_default_target_falls_back_to_prompting_with_no_booted_simulator()
    {
        $devices = [
            [
                'name' => 'iPhone 17 Pro',
                'version' => '18.6',
                'udid' => 'FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6',
                'category' => 'Simulators',
            ],
            [
                'name' => 'iPhone 17',
                'version' => '18.6',
                'udid' => '0D25A1A9-959C-404C-8E40-7863A7AF508F',
                'category' => 'Simulators',
            ],
        ];

        Process::fake([
            '*simctl*list*devices*booted*' => Process::result(json_encode([
                'devices' => [
                    'com.apple.CoreSimulator.SimRuntime.iOS-18-6' => [],
                ],
            ])),
        ]);

        $this->expectException(NonInteractiveValidationException::class);

        $this->resolveDefaultIosTarget($devices);
    }

    public function test_resolve_default_target_ignores_a_lone_booted_watch_simulator()
    {
        // Only one simulator is booted overall, but it's a Watch — the iOS
        // device list has neither of these UDIDs, so falling through to the
        // (single-candidate) prompt shortcut proves the Watch was filtered
        // out rather than wrongly auto-selected.
        $devices = [
            [
                'name' => 'iPhone 17 Pro',
                'version' => '18.6',
                'udid' => 'FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6',
                'category' => 'Simulators',
            ],
        ];

        Process::fake([
            '*simctl*list*devices*booted*' => Process::result(json_encode([
                'devices' => [
                    'com.apple.CoreSimulator.SimRuntime.watchOS-10-0' => [
                        ['udid' => '11111111-2222-3333-4444-555555555555', 'state' => 'Booted'],
                    ],
                ],
            ])),
        ]);

        $target = $this->resolveDefaultIosTarget($devices);

        $this->assertSame('FC4BFF3D-8B7B-4331-ACB7-ED78DCC313A6', $target);
    }
}
