<?php

namespace Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `native:build --simulated` needed a simulator UDID to produce a simulator
 * .app. Without one it fell through to `xcodebuild archive`, so CI had to
 * find a simulator it would never boot just to get a build.
 */
class IosSimulatorBuildTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = realpath(sys_get_temp_dir()).'/nativephp_ios_simulator_build_test_'.uniqid();
        File::ensureDirectoryExists($this->testProjectPath.'/nativephp/ios');
        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);

        parent::tearDown();
    }

    public function test_simulated_build_without_a_target_builds_for_any_simulator(): void
    {
        $command = $this->commandWith(['--simulated' => true]);

        $this->assertSame([
            '-destination', 'generic/platform=iOS Simulator',
            'ARCHS=arm64',
            'build',
        ], array_slice($command->xcodebuildCommand(), -4));
        $this->assertNotContains('archive', $command->xcodebuildCommand());
        $this->assertContains('iphonesimulator', $command->xcodebuildCommand());
        $this->assertContains('NativePHP-simulator', $command->xcodebuildCommand());
    }

    public function test_simulated_build_with_a_target_still_builds_for_that_simulator(): void
    {
        $command = $this->commandWith(['--simulated' => true, '--target' => 'SIM-UDID']);

        $this->assertSame(
            ['-destination', 'id=SIM-UDID', 'build'],
            array_slice($command->xcodebuildCommand(), -3)
        );
    }

    public function test_device_builds_are_unchanged(): void
    {
        $this->assertSame(
            ['-destination', 'id=DEVICE-UDID,platform=iOS', 'build'],
            array_slice($this->commandWith(['--target' => 'DEVICE-UDID'])->xcodebuildCommand(), -3)
        );

        $this->assertSame(
            ['-archivePath', $this->testProjectPath.'/nativephp/ios/build/NativePHP.xcarchive', 'archive'],
            array_slice($this->commandWith(['--release' => true])->xcodebuildCommand(), -3)
        );
    }

    public function test_simulator_app_lands_in_a_fixed_place(): void
    {
        $this->assertSame(
            $this->testProjectPath.'/nativephp/ios/build/Build/Products/Debug-iphonesimulator/NativePHP-simulator.app',
            $this->commandWith(['--simulated' => true])->simulatorAppPath()
        );
    }

    /**
     * The simulator target signed with no entitlements, so the `applinks:`
     * domain the build writes never reached a simulator app and universal
     * links could only be tried on a device.
     */
    public function test_simulator_app_is_signed_with_the_entitlements_the_build_writes(): void
    {
        config(['nativephp.deeplink_host' => 'example.com?mode=developer']);
        File::ensureDirectoryExists($this->testProjectPath.'/nativephp/ios/NativePHP');

        $entitlements = $this->simulatorEntitlementsSettings();

        $this->assertSame([
            'Debug' => 'NativePHP/NativePHP.entitlements',
            'Release' => 'NativePHP/NativePHP.entitlements',
        ], $entitlements);

        $command = new BuildIosAppCommand;
        $reflection = new ReflectionClass($command);
        $reflection->getProperty('containerPath')->setValue($command, $this->testProjectPath.'/nativephp/ios/NativePHP/');
        $reflection->getMethod('updateEntitlementsFile')->invoke($command);

        $this->assertStringContainsString(
            '<string>applinks:example.com?mode=developer</string>',
            File::get($this->testProjectPath.'/nativephp/ios/'.$entitlements['Debug'])
        );
    }

    /**
     * CODE_SIGN_ENTITLEMENTS for each build configuration of the simulator
     * target, in the Xcode project `native:install ios` copies into an app.
     *
     * @return array<string, string|null>
     */
    private function simulatorEntitlementsSettings(): array
    {
        $pbxproj = File::get(dirname(__DIR__, 2).'/resources/xcode/NativePHP.xcodeproj/project.pbxproj');

        preg_match_all('/\t\t\w+ \/\* (\w+) \*\/ = \{\n\t\t\tisa = XCBuildConfiguration;.*?\n\t\t\};/s', $pbxproj, $configurations, PREG_SET_ORDER);

        $settings = [];

        foreach ($configurations as [$configuration, $name]) {
            if (! str_contains($configuration, 'INFOPLIST_FILE = "NativePHP-simulator-Info.plist";')) {
                continue;
            }

            preg_match('/CODE_SIGN_ENTITLEMENTS = (.+?);/', $configuration, $entitlements);
            $settings[$name] = $entitlements[1] ?? null;
        }

        return $settings;
    }

    private function commandWith(array $options): IosSimulatorBuildProbe
    {
        $command = new IosSimulatorBuildProbe;
        $command->setLaravel($this->app);

        $input = new ArrayInput($options, $command->getDefinition());
        $command->prime($input, new OutputStyle($input, new BufferedOutput), $this->testProjectPath.'/nativephp/ios');

        return $command;
    }
}

class IosSimulatorBuildProbe extends BuildIosAppCommand
{
    public function prime(ArrayInput $input, OutputStyle $output, string $basePath): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->components = new Factory($output);
        $this->basePath = $basePath;
        $this->target = $input->getOption('target');
    }

    public function xcodebuildCommand(): array
    {
        return parent::xcodebuildCommand();
    }

    public function simulatorAppPath(): string
    {
        return parent::simulatorAppPath();
    }
}
