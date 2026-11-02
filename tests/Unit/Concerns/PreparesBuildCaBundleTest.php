<?php

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\File;
use Native\Mobile\Concerns\PreparesBuild;
use SupaNative\Core\Support\CaBundle;
use Tests\TestCase;

class PreparesBuildCaBundleTest extends TestCase
{
    use PreparesBuild;

    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_prepare_build_ca_bundle_test_'.uniqid();
        File::makeDirectory($this->testProjectPath.'/nativephp/android/app/src/main/res', 0755, true);

        // Set up base path for testing
        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);

        parent::tearDown();
    }

    public function test_prepare_android_build_replaces_an_older_ca_bundle_with_cores()
    {
        // Create an installed project with an older copy
        $assetsPath = $this->testProjectPath.'/nativephp/android/app/src/main/assets';
        File::makeDirectory($assetsPath, 0755, true);
        File::put($assetsPath.'/cacert.pem', 'older bundle');

        // Execute
        $this->prepareAndroidBuild(cleanCache: false);

        // Assert core's file replaced the older copy
        $this->assertFileEquals(CaBundle::path(), $assetsPath.'/cacert.pem');
    }

    protected function updateAndroidConfiguration(): void
    {
        // Covered by ConfigurationUpdatesTest.
    }

    protected function prepareLaravelBundle(bool $excludeDevDependencies = true, bool $reuseUnchanged = false): void
    {
        // Covered by SkipUnchangedBuildStepsTest.
    }

    protected function logToFile(string $message): void {}

    protected function removeDirectory(string $path): void {}

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
