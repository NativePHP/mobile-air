<?php

namespace Tests\Unit\Traits;

use Illuminate\Support\Facades\File;
use Native\Mobile\Traits\InstallsAndroidSplashScreen;
use Native\Mobile\Traits\InstallsAppIcon;
use Tests\TestCase;

class PreparesBuildSplashTest extends TestCase
{
    use InstallsAndroidSplashScreen, InstallsAppIcon;

    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_prepare_build_test_'.uniqid();

        // Set up directory structure
        File::makeDirectory($this->testProjectPath.'/public', 0755, true);
        File::makeDirectory($this->testProjectPath.'/nativephp/android/app/src/main/res', 0755, true);

        // Set up base path for testing
        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_splash_screen_integration_works_in_build_context()
    {
        // Create test splash image
        $this->createTestSplashImage();

        // Test that the method works in a build-like context
        $this->installAndroidSplashScreen();

        // Assert that splash images were generated
        $splashPath = $this->testProjectPath.'/nativephp/android/app/src/main/res/drawable-mdpi/splash.png';
        $this->assertFileExists($splashPath);
    }

    public function test_splash_screen_integration_skips_gracefully_without_image()
    {
        // Don't create any splash image

        // Execute - should not fail even without splash image
        $this->installAndroidSplashScreen();

        // Assert no splash image was generated
        $splashPath = $this->testProjectPath.'/nativephp/android/app/src/main/res/drawable-mdpi/splash.png';
        $this->assertFileDoesNotExist($splashPath);
    }

    /**
     * Create a test splash image
     */
    protected function createTestSplashImage(): void
    {
        $splashPath = $this->testProjectPath.'/public/splash.png';

        $image = imagecreatetruecolor(1080, 1920);
        $blue = imagecolorallocate($image, 0, 100, 200);
        imagefill($image, 0, 0, $blue);

        imagepng($image, $splashPath);
        imagedestroy($image);
    }

    protected function logToFile(string $message): void {}

    protected function info($message): void
    {
        // Mock implementation
    }

    protected function warn($message): void
    {
        // Mock implementation
    }
}
