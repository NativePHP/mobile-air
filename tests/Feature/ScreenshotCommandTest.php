<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class ScreenshotCommandTest extends TestCase
{
    protected string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = sys_get_temp_dir().'/nativephp_screenshot_test_'.uniqid();
        File::makeDirectory($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }

    public function test_it_rejects_an_unknown_platform(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', ['os' => 'blackberry', '--output' => $this->outputDir.'/shot.png'])
            ->expectsOutputToContain("Invalid platform 'blackberry'")
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_requires_an_output_path(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', ['os' => 'ios'])
            ->expectsOutputToContain('--output is required')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_rejects_an_output_path_whose_directory_does_not_exist(): void
    {
        Process::fake();

        $missingDir = $this->outputDir.'/does-not-exist/shot.png';

        $this->artisan('native:screenshot', ['os' => 'ios', '--output' => $missingDir])
            ->expectsOutputToContain('Output directory does not exist')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_ios_capture_defaults_to_the_booted_simulator(): void
    {
        Process::fake();

        $outputPath = $this->outputDir.'/shot.png';

        $this->artisan('native:screenshot', ['os' => 'ios', '--output' => $outputPath])
            ->assertSuccessful();

        // assertRan()'s $callback must be a Closure|string, so an array
        // command (as this class passes to Process::run()) is compared here
        // rather than being handed to assertRan() directly.
        Process::assertRan(fn ($process) => $process->command === ['xcrun', 'simctl', 'io', 'booted', 'screenshot', $outputPath]);
    }

    public function test_ios_capture_uses_the_given_udid(): void
    {
        Process::fake();

        $outputPath = $this->outputDir.'/shot.png';
        $udid = 'ABCD-1234';

        $this->artisan('native:screenshot', ['os' => 'i', 'udid' => $udid, '--output' => $outputPath])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command === ['xcrun', 'simctl', 'io', $udid, 'screenshot', $outputPath]);
    }

    public function test_ios_capture_reports_no_booted_simulator(): void
    {
        // Verified against a real `xcrun simctl io booted screenshot` with
        // nothing booted — this is the actual stderr text, not a guess.
        Process::fake([
            '*simctl*booted*screenshot*' => Process::result(errorOutput: 'No devices are booted.', exitCode: 148),
        ]);

        $this->artisan('native:screenshot', ['os' => 'ios', '--output' => $this->outputDir.'/shot.png'])
            ->expectsOutputToContain('No booted simulator found.')
            ->assertFailed();
    }

    public function test_ios_capture_reports_an_invalid_udid(): void
    {
        // Verified against a real `xcrun simctl io <bogus-udid> screenshot`.
        Process::fake([
            '*simctl*screenshot*' => Process::result(errorOutput: 'Invalid device: NOT-A-REAL-UDID', exitCode: 148),
        ]);

        $this->artisan('native:screenshot', ['os' => 'ios', 'udid' => 'NOT-A-REAL-UDID', '--output' => $this->outputDir.'/shot.png'])
            ->expectsOutputToContain("No simulator found for target 'NOT-A-REAL-UDID'")
            ->assertFailed();
    }

    public function test_android_capture_fails_when_adb_is_not_installed(): void
    {
        Process::fake([
            'adb version' => Process::result(exitCode: 127),
        ]);

        $this->artisan('native:screenshot', ['os' => 'android', '--output' => $this->outputDir.'/shot.png'])
            ->expectsOutputToContain('ADB is not installed or not in your PATH')
            ->assertFailed();

        Process::assertDidntRun('adb devices');
    }

    public function test_android_capture_uses_the_only_connected_device(): void
    {
        Process::fake([
            'adb version' => Process::result(),
            'adb devices' => Process::result(output: "List of devices attached\nemulator-5554\tdevice\n"),
            '*screencap*' => Process::result(),
        ]);

        $outputPath = $this->outputDir.'/shot.png';

        $this->artisan('native:screenshot', ['os' => 'android', '--output' => $outputPath])
            ->assertSuccessful();

        // Every interpolated value is escapeshellarg()'d before this string
        // reaches the shell, so the recorded command carries single quotes.
        Process::assertRan(sprintf("'adb' -s 'emulator-5554' exec-out screencap -p > '%s'", $outputPath));
    }

    public function test_android_capture_escapes_an_output_path_containing_a_space(): void
    {
        Process::fake([
            'adb version' => Process::result(),
            'adb devices' => Process::result(output: "List of devices attached\nemulator-5554\tdevice\n"),
            '*screencap*' => Process::result(),
        ]);

        $outputPath = $this->outputDir.'/My Screenshots/shot.png';
        File::makeDirectory(dirname($outputPath), 0755, true);

        $this->artisan('native:screenshot', ['os' => 'android', '--output' => $outputPath])
            ->assertSuccessful();

        Process::assertRan(sprintf("'adb' -s 'emulator-5554' exec-out screencap -p > '%s'", $outputPath));
    }

    public function test_android_capture_uses_the_given_udid_without_querying_devices(): void
    {
        Process::fake([
            'adb version' => Process::result(),
            '*screencap*' => Process::result(),
        ]);

        $outputPath = $this->outputDir.'/shot.png';

        $this->artisan('native:screenshot', ['os' => 'a', 'udid' => 'emulator-9999', '--output' => $outputPath])
            ->assertSuccessful();

        Process::assertDidntRun('adb devices');
        Process::assertRan(sprintf("'adb' -s 'emulator-9999' exec-out screencap -p > '%s'", $outputPath));
    }

    public function test_android_capture_fails_when_no_device_is_connected(): void
    {
        Process::fake([
            'adb version' => Process::result(),
            'adb devices' => Process::result(output: "List of devices attached\n"),
        ]);

        $this->artisan('native:screenshot', ['os' => 'android', '--output' => $this->outputDir.'/shot.png'])
            ->expectsOutputToContain('No connected Android device or emulator found')
            ->assertFailed();
    }

    public function test_android_capture_auto_selects_a_purely_numeric_serial(): void
    {
        // A serial that's all digits (some real device serials are) becomes
        // an int array key under PHP's numeric-string casting — regression
        // test for a TypeError this used to throw via array_key_first().
        Process::fake([
            'adb version' => Process::result(),
            'adb devices' => Process::result(output: "List of devices attached\n1234567890\tdevice\n"),
            '*screencap*' => Process::result(),
        ]);

        $outputPath = $this->outputDir.'/shot.png';

        $this->artisan('native:screenshot', ['os' => 'android', '--output' => $outputPath])
            ->assertSuccessful();

        Process::assertRan(sprintf("'adb' -s '1234567890' exec-out screencap -p > '%s'", $outputPath));
    }

    public function test_android_capture_fails_when_multiple_devices_are_connected_and_no_udid_given(): void
    {
        Process::fake([
            'adb version' => Process::result(),
            'adb devices' => Process::result(output: "List of devices attached\nemulator-5554\tdevice\nemulator-5556\tdevice\n"),
        ]);

        $this->artisan('native:screenshot', ['os' => 'android', '--output' => $this->outputDir.'/shot.png'])
            ->expectsOutputToContain('Multiple Android devices found (emulator-5554, emulator-5556). Pass a udid to pick one.')
            ->assertFailed();

        // This is a genuine ambiguity, not "nothing found" — must not be
        // reported as the zero-devices case.
        Process::assertRan('adb devices');
    }

    public function test_it_rejects_an_invalid_crop_value(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', ['os' => 'ios', '--output' => $this->outputDir.'/shot.png', '--crop' => 'left'])
            ->expectsOutputToContain("Invalid --crop 'left'")
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_rejects_an_out_of_range_crop_percent(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $this->outputDir.'/shot.png',
            '--crop' => 'top',
            '--crop-percent' => '1.5',
        ])
            ->expectsOutputToContain('--crop-percent must be between 0 and 1')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_rejects_a_crop_percent_of_exactly_one_for_a_single_edge(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $this->outputDir.'/shot.png',
            '--crop' => 'top',
            '--crop-percent' => '1',
        ])
            ->expectsOutputToContain('--crop-percent must be between 0 and 1')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_ios_capture_crops_to_a_top_strip(): void
    {
        $outputPath = $this->outputDir.'/shot.png';

        Process::fake([
            '*simctl*screenshot*' => function () use ($outputPath) {
                $this->writeTestPng($outputPath, 200, 1000);

                return Process::result();
            },
        ]);

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $outputPath,
            '--crop' => 'top',
            '--crop-percent' => '0.3',
        ])->assertSuccessful();

        $cropped = imagecreatefrompng($outputPath);
        $this->assertSame(200, imagesx($cropped));
        $this->assertSame(300, imagesy($cropped));
        imagedestroy($cropped);
    }

    public function test_capture_crops_both_edges_and_keeps_the_middle(): void
    {
        $outputPath = $this->outputDir.'/shot.png';

        Process::fake([
            '*simctl*screenshot*' => function () use ($outputPath) {
                $this->writeTestPng($outputPath, 200, 1000);

                return Process::result();
            },
        ]);

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $outputPath,
            '--crop' => 'both',
            '--crop-percent' => '0.1',
        ])->assertSuccessful();

        // 10% trimmed off each of the 1000px edges leaves the middle 800px.
        $cropped = imagecreatefrompng($outputPath);
        $this->assertSame(200, imagesx($cropped));
        $this->assertSame(800, imagesy($cropped));
        imagedestroy($cropped);
    }

    public function test_it_rejects_a_crop_percent_of_half_or_more_for_both_edges(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $this->outputDir.'/shot.png',
            '--crop' => 'both',
            '--crop-percent' => '0.5',
        ])
            ->expectsOutputToContain('--crop-percent must be between 0 and 0.5')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_capture_fails_cleanly_when_a_near_ceiling_both_crop_percent_rounds_away_the_whole_image(): void
    {
        $outputPath = $this->outputDir.'/shot.png';

        // 1000 * 0.4995 = 499.5, which rounds to 500 per edge — 2 * 500 is
        // the entire image height, leaving nothing for the middle. This is
        // a valid --crop-percent by the (0, 0.5) exclusive range check, so
        // only the crop step itself (not upfront validation) can catch it.
        Process::fake([
            '*simctl*screenshot*' => function () use ($outputPath) {
                $this->writeTestPng($outputPath, 200, 1000);

                return Process::result();
            },
        ]);

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $outputPath,
            '--crop' => 'both',
            '--crop-percent' => '0.4995',
        ])
            ->expectsOutputToContain('failed to crop')
            ->assertFailed();
    }

    public function test_ios_capture_crops_to_a_top_strip_excluding_an_offset(): void
    {
        $outputPath = $this->outputDir.'/shot.png';

        Process::fake([
            '*simctl*screenshot*' => function () use ($outputPath) {
                $this->writeTestPng($outputPath, 200, 1000);

                return Process::result();
            },
        ]);

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $outputPath,
            '--crop' => 'top',
            '--crop-percent' => '0.3',
            '--crop-offset' => '0.1',
        ])->assertSuccessful();

        // Skips the first 10% (100px, the "status bar"), then keeps a 30%
        // (300px) strip below it.
        $cropped = imagecreatefrompng($outputPath);
        $this->assertSame(200, imagesx($cropped));
        $this->assertSame(300, imagesy($cropped));
        imagedestroy($cropped);
    }

    public function test_android_capture_crops_to_a_bottom_strip_excluding_an_offset(): void
    {
        $outputPath = $this->outputDir.'/shot.png';

        Process::fake([
            'adb version' => Process::result(),
            'adb devices' => Process::result(output: "List of devices attached\nemulator-5554\tdevice\n"),
            '*screencap*' => function () use ($outputPath) {
                $this->writeTestPng($outputPath, 200, 1000);

                return Process::result();
            },
        ]);

        $this->artisan('native:screenshot', [
            'os' => 'android',
            '--output' => $outputPath,
            '--crop' => 'bottom',
            '--crop-percent' => '0.2',
            '--crop-offset' => '0.05',
        ])->assertSuccessful();

        // Skips the last 5% (50px, e.g. the home indicator), then keeps a
        // 20% (200px) strip above it.
        $cropped = imagecreatefrompng($outputPath);
        $this->assertSame(200, imagesx($cropped));
        $this->assertSame(200, imagesy($cropped));
        imagedestroy($cropped);
    }

    public function test_it_rejects_crop_offset_with_both_edges(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $this->outputDir.'/shot.png',
            '--crop' => 'both',
            '--crop-percent' => '0.1',
            '--crop-offset' => '0.1',
        ])
            ->expectsOutputToContain('--crop-offset is not supported with --crop=both')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_rejects_a_crop_offset_that_leaves_no_room_for_crop_percent(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $this->outputDir.'/shot.png',
            '--crop' => 'top',
            '--crop-percent' => '0.3',
            '--crop-offset' => '0.8',
        ])
            ->expectsOutputToContain('--crop-offset (0.8) plus --crop-percent (0.3) must be less than 1')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_rejects_a_negative_crop_offset(): void
    {
        Process::fake();

        $this->artisan('native:screenshot', [
            'os' => 'ios',
            '--output' => $this->outputDir.'/shot.png',
            '--crop' => 'top',
            '--crop-percent' => '0.3',
            '--crop-offset' => '-0.1',
        ])
            ->expectsOutputToContain('--crop-offset (-0.1) plus --crop-percent (0.3) must be less than 1')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_android_capture_crops_to_a_bottom_strip(): void
    {
        $outputPath = $this->outputDir.'/shot.png';

        Process::fake([
            'adb version' => Process::result(),
            'adb devices' => Process::result(output: "List of devices attached\nemulator-5554\tdevice\n"),
            '*screencap*' => function () use ($outputPath) {
                $this->writeTestPng($outputPath, 200, 1000);

                return Process::result();
            },
        ]);

        $this->artisan('native:screenshot', [
            'os' => 'android',
            '--output' => $outputPath,
            '--crop' => 'bottom',
            '--crop-percent' => '0.2',
        ])->assertSuccessful();

        $cropped = imagecreatefrompng($outputPath);
        $this->assertSame(200, imagesx($cropped));
        $this->assertSame(200, imagesy($cropped));
        imagedestroy($cropped);
    }

    public function test_capture_without_crop_leaves_the_image_full_size(): void
    {
        $outputPath = $this->outputDir.'/shot.png';

        Process::fake([
            '*simctl*screenshot*' => function () use ($outputPath) {
                $this->writeTestPng($outputPath, 200, 1000);

                return Process::result();
            },
        ]);

        $this->artisan('native:screenshot', ['os' => 'ios', '--output' => $outputPath])
            ->assertSuccessful();

        $full = imagecreatefrompng($outputPath);
        $this->assertSame(1000, imagesy($full));
        imagedestroy($full);
    }

    private function writeTestPng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $path);
        imagedestroy($image);
    }
}
