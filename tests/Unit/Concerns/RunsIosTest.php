<?php

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\File;
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
}
