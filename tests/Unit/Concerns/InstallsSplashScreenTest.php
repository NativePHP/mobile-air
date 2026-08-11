<?php

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\File;
use Native\Mobile\Concerns\InstallsSplashScreen;
use Orchestra\Testbench\TestCase;

/**
 * The launch image set is generated from the app's own splash artwork on every
 * build, so what it holds afterwards is what the build put there — not the
 * union of every build that came before it.
 */
class InstallsSplashScreenTest extends TestCase
{
    use InstallsSplashScreen;

    protected string $projectPath;

    protected string $imageSet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_splash_test_'.uniqid();
        $this->imageSet = $this->projectPath.'/nativephp/ios/NativePHP/Assets.xcassets/LaunchImage.imageset';

        File::makeDirectory($this->projectPath.'/public', 0755, true);
        File::makeDirectory($this->imageSet, 0755, true);

        app()->setBasePath($this->projectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    /**
     * Large enough to pass validation at the given scale.
     */
    protected function writeSplash(string $filename, int $scale = 1): void
    {
        $image = imagecreatetruecolor(375 * $scale, 667 * $scale);
        imagepng($image, $this->projectPath.'/public/'.$filename);
        imagedestroy($image);
    }

    public function test_it_drops_a_variant_the_app_no_longer_ships(): void
    {
        $this->writeSplash('splash.png');
        $this->writeSplash('splash-dark.png');

        $this->installIosSplashScreen();

        $this->assertFileExists($this->imageSet.'/splash-dark.png');

        File::delete($this->projectPath.'/public/splash-dark.png');

        $this->installIosSplashScreen();

        $this->assertFileExists($this->imageSet.'/splash.png');
        $this->assertFileDoesNotExist($this->imageSet.'/splash-dark.png');
        $this->assertStringNotContainsString(
            'splash-dark.png',
            File::get($this->imageSet.'/Contents.json')
        );
    }

    /**
     * A plugin may replace the set with one of its own — a vector, say. Core
     * owns the set, so the next build takes it back whole rather than writing
     * bitmaps beside content the asset compiler rejects them next to.
     */
    public function test_it_clears_content_it_did_not_write(): void
    {
        File::put($this->imageSet.'/splash.svg', '<svg xmlns="http://www.w3.org/2000/svg" />');

        $this->writeSplash('splash.png');

        $this->installIosSplashScreen();

        $this->assertFileDoesNotExist($this->imageSet.'/splash.svg');
        $this->assertFileExists($this->imageSet.'/splash.png');
    }

    /**
     * The installed project ships a default set. An app with no splash artwork
     * of its own has nothing to replace it with, so it keeps it.
     */
    public function test_it_keeps_the_installed_default_when_the_app_has_no_splash(): void
    {
        File::put($this->imageSet.'/splash.png', 'installed default');
        File::put($this->imageSet.'/Contents.json', '{}');

        $this->installIosSplashScreen();

        $this->assertSame('installed default', File::get($this->imageSet.'/splash.png'));
    }
}
