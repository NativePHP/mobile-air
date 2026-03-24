<?php

namespace Tests\Unit\Traits;

use Illuminate\Support\Facades\File;
use Native\Mobile\Traits\InstallsAndroidSplashScreen;
use Native\Mobile\Traits\InstallsAppIcon;
use Tests\TestCase;

class InstallsAndroidSplashScreenTest extends TestCase
{
    use InstallsAndroidSplashScreen, InstallsAppIcon;

    protected string $testProjectPath;

    protected string $publicPath;

    protected string $androidResPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_splash_test_'.uniqid();
        $this->publicPath = $this->testProjectPath.'/public';
        $this->androidResPath = $this->testProjectPath.'/nativephp/android/app/src/main/res';

        // Set up directory structure
        File::makeDirectory($this->publicPath, 0755, true);
        File::makeDirectory($this->androidResPath, 0755, true);

        // Set up base path for testing
        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_installs_android_splash_screen_with_valid_image()
    {
        // Create a test splash image
        $this->createTestSplashImage();

        // Execute the method we're testing
        $this->installAndroidSplashScreen();

        // Assert that splash images were generated for all densities
        $densities = ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'];
        foreach ($densities as $density) {
            $expectedPath = $this->androidResPath."/drawable-{$density}/splash.png";
            $this->assertFileExists($expectedPath, "Splash image not generated for {$density}");
        }

        // Verify images are properly sized
        $this->assertSplashImageDimensions();
    }

    public function test_skips_splash_when_no_image_exists()
    {
        // Ensure no splash image exists
        $splashPath = $this->publicPath.'/splash.png';
        $this->assertFileDoesNotExist($splashPath);

        // Execute the method
        $this->installAndroidSplashScreen();

        // Assert no splash images were generated
        $densities = ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'];
        foreach ($densities as $density) {
            $expectedPath = $this->androidResPath."/drawable-{$density}/splash.png";
            $this->assertFileDoesNotExist($expectedPath, "Splash image should not exist for {$density}");
        }
    }

    public function test_generates_all_density_variants()
    {
        // Create test image
        $this->createTestSplashImage();

        // Execute
        $this->installAndroidSplashScreen();

        // Define expected sizes for each density
        $expectedSizes = [
            'mdpi' => [320, 480],     // 1x
            'hdpi' => [480, 720],     // 1.5x
            'xhdpi' => [640, 960],    // 2x
            'xxhdpi' => [960, 1440],  // 3x
            'xxxhdpi' => [1280, 1920], // 4x
        ];

        foreach ($expectedSizes as $density => $expectedSize) {
            $imagePath = $this->androidResPath."/drawable-{$density}/splash.png";
            $this->assertFileExists($imagePath);

            // Verify image dimensions
            $imageInfo = getimagesize($imagePath);
            $this->assertEquals($expectedSize[0], $imageInfo[0], "Width mismatch for {$density}");
            $this->assertEquals($expectedSize[1], $imageInfo[1], "Height mismatch for {$density}");
        }
    }

    public function test_validates_image_format()
    {
        // Create an invalid (non-PNG) file
        $invalidImagePath = $this->publicPath.'/splash.png';
        File::put($invalidImagePath, 'not a png image');

        // Execute - should handle gracefully
        $this->installAndroidSplashScreen();

        // Assert no splash images were generated due to invalid format
        $splashPath = $this->androidResPath.'/drawable-mdpi/splash.png';
        $this->assertFileDoesNotExist($splashPath);
    }

    public function test_handles_invalid_images_gracefully()
    {
        // Create a corrupted PNG file
        $corruptImagePath = $this->publicPath.'/splash.png';
        File::put($corruptImagePath, "\x89PNG\r\n\x1a\ncorrupted data");

        // Execute - should not throw exception
        $this->installAndroidSplashScreen();

        // Assert no splash images were generated
        $splashPath = $this->androidResPath.'/drawable-mdpi/splash.png';
        $this->assertFileDoesNotExist($splashPath);
    }

    public function test_installs_dark_mode_splash_screen()
    {
        // Create test dark mode splash image
        $this->createTestDarkSplashImage();

        // Execute the method
        $this->installAndroidSplashScreen();

        // Assert that dark mode splash images were generated for all densities
        $densities = ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'];
        foreach ($densities as $density) {
            $expectedPath = $this->androidResPath."/drawable-night-{$density}/splash.png";
            $this->assertFileExists($expectedPath, "Dark mode splash image not generated for {$density}");
        }

        // Verify dark mode images are properly sized
        $this->assertDarkSplashImageDimensions();
    }

    public function test_installs_both_light_and_dark_splash_screens()
    {
        // Create both light and dark mode splash images
        $this->createTestSplashImage(); // Light mode
        $this->createTestDarkSplashImage(); // Dark mode

        // Execute the method
        $this->installAndroidSplashScreen();

        $densities = ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'];

        // Assert light mode images exist
        foreach ($densities as $density) {
            $lightPath = $this->androidResPath."/drawable-{$density}/splash.png";
            $this->assertFileExists($lightPath, "Light mode splash image not generated for {$density}");
        }

        // Assert dark mode images exist
        foreach ($densities as $density) {
            $darkPath = $this->androidResPath."/drawable-night-{$density}/splash.png";
            $this->assertFileExists($darkPath, "Dark mode splash image not generated for {$density}");
        }
    }

    public function test_dark_mode_only_splash_screen()
    {
        // Only create dark mode splash image (no light mode)
        $this->createTestDarkSplashImage();

        // Execute the method
        $this->installAndroidSplashScreen();

        $densities = ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'];

        // Assert light mode images do NOT exist
        foreach ($densities as $density) {
            $lightPath = $this->androidResPath."/drawable-{$density}/splash.png";
            $this->assertFileDoesNotExist($lightPath, "Light mode splash should not exist for {$density}");
        }

        // Assert dark mode images DO exist
        foreach ($densities as $density) {
            $darkPath = $this->androidResPath."/drawable-night-{$density}/splash.png";
            $this->assertFileExists($darkPath, "Dark mode splash image should exist for {$density}");
        }
    }

    public function test_skips_invalid_dark_mode_image()
    {
        // Create valid light mode and invalid dark mode
        $this->createTestSplashImage(); // Valid light
        File::put($this->publicPath.'/splash-dark.png', 'invalid image data'); // Invalid dark

        // Execute the method
        $this->installAndroidSplashScreen();

        $densities = ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'];

        // Assert light mode images exist (valid)
        foreach ($densities as $density) {
            $lightPath = $this->androidResPath."/drawable-{$density}/splash.png";
            $this->assertFileExists($lightPath, "Light mode splash should exist for {$density}");
        }

        // Assert dark mode images do NOT exist (invalid)
        foreach ($densities as $density) {
            $darkPath = $this->androidResPath."/drawable-night-{$density}/splash.png";
            $this->assertFileDoesNotExist($darkPath, "Dark mode splash should not exist for {$density} (invalid image)");
        }
    }

    /**
     * Helper method to create a test splash image
     */
    protected function createTestSplashImage(): void
    {
        $splashPath = $this->publicPath.'/splash.png';

        // Create a simple PNG image using GD
        $image = imagecreatetruecolor(1080, 1920); // Full HD portrait
        $blue = imagecolorallocate($image, 0, 100, 200);
        imagefill($image, 0, 0, $blue);

        // Add some text to make it identifiable
        $white = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 450, 960, 'SPLASH', $white);

        imagepng($image, $splashPath);
        imagedestroy($image);
    }

    /**
     * Helper method to create a test dark mode splash image
     */
    protected function createTestDarkSplashImage(): void
    {
        $darkSplashPath = $this->publicPath.'/splash-dark.png';

        // Create a dark themed PNG image using GD
        $image = imagecreatetruecolor(1080, 1920); // Full HD portrait
        $darkBlue = imagecolorallocate($image, 15, 30, 60); // Dark background
        imagefill($image, 0, 0, $darkBlue);

        // Add some text to make it identifiable as dark mode
        $lightGray = imagecolorallocate($image, 200, 200, 200);
        imagestring($image, 5, 420, 960, 'DARK SPLASH', $lightGray);

        imagepng($image, $darkSplashPath);
        imagedestroy($image);
    }

    /**
     * Assert that generated splash images have correct dimensions
     */
    protected function assertSplashImageDimensions(): void
    {
        $mdpiPath = $this->androidResPath.'/drawable-mdpi/splash.png';
        $imageInfo = getimagesize($mdpiPath);

        $this->assertNotFalse($imageInfo, 'Generated image should be valid');
        $this->assertEquals(320, $imageInfo[0], 'MDPI width should be 320px');
        $this->assertEquals(480, $imageInfo[1], 'MDPI height should be 480px');
    }

    /**
     * Assert that generated dark mode splash images have correct dimensions
     */
    protected function assertDarkSplashImageDimensions(): void
    {
        $darkMdpiPath = $this->androidResPath.'/drawable-night-mdpi/splash.png';
        $imageInfo = getimagesize($darkMdpiPath);

        $this->assertNotFalse($imageInfo, 'Generated dark mode image should be valid');
        $this->assertEquals(320, $imageInfo[0], 'Dark mode MDPI width should be 320px');
        $this->assertEquals(480, $imageInfo[1], 'Dark mode MDPI height should be 480px');
    }

    protected function logToFile(string $message): void {}

    /**
     * Mock methods for testing (these will be implemented in the actual trait)
     */
    protected function info($message): void
    {
        // Mock implementation for testing
    }

    protected function warn($message): void
    {
        // Mock implementation for testing
    }

    protected function error($message): void
    {
        // Mock implementation for testing
    }
}
