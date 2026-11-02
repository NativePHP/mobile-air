<?php

namespace Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use SupaNative\Core\Support\CaBundle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class IosBuildCaBundleTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_ios_build_ca_bundle_test_'.uniqid();
        File::makeDirectory($this->testProjectPath.'/nativephp/ios/NativePHP', 0755, true);

        app()->setBasePath($this->testProjectPath);

    }

    protected function tearDown(): void
    {
        putenv('NATIVEPHP_XCODE_BUILD');
        File::deleteDirectory($this->testProjectPath);

        parent::tearDown();
    }

    public function test_an_xcode_driven_build_replaces_an_older_ca_bundle_with_the_one_from_core(): void
    {
        // Create an installed project with an older copy
        $caBundlePath = $this->testProjectPath.'/nativephp/ios/NativePHP/cacert.pem';
        File::put($caBundlePath, 'older bundle');

        // Execute the build as Xcode's build phase starts it
        putenv('NATIVEPHP_XCODE_BUILD=1');
        $result = $this->makeCommand()->handle();

        // Assert core's file replaced the older copy
        $this->assertSame(BuildIosAppCommand::SUCCESS, $result);
        $this->assertFileEquals(CaBundle::path(), $caBundlePath);
    }

    private function makeCommand(): IosBuildCaBundleProbe
    {
        $command = new IosBuildCaBundleProbe;
        $command->setLaravel($this->app);

        $input = new ArrayInput([], $command->getDefinition());
        $command->prime($input, new OutputStyle($input, new BufferedOutput));

        return $command;
    }
}

class IosBuildCaBundleProbe extends BuildIosAppCommand
{
    public function prime(ArrayInput $input, OutputStyle $output): void
    {
        $this->input = $input;
        $this->output = $output;
    }

    protected function runningOnMacOs(): bool
    {
        return true;
    }

    protected function bundleLaravelApp(): void
    {
        // Covered by SkipUnchangedBuildStepsTest.
    }
}
