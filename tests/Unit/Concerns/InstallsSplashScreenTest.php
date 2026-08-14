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
     * An app that drops its splash artwork falls back to the set a fresh
     * install ships, not to whatever the last build with artwork left behind.
     */
    public function test_it_restores_the_shipped_default_when_the_app_has_no_splash(): void
    {
        $this->writeSplash('splash.png');
        $this->installIosSplashScreen();

        File::delete($this->projectPath.'/public/splash.png');

        $this->installIosSplashScreen();

        $default = dirname(__DIR__, 3).'/resources/xcode/NativePHP/Assets.xcassets/LaunchImage.imageset';

        $this->assertFileEquals($default.'/splash.png', $this->imageSet.'/splash.png');
        $this->assertFileEquals($default.'/splash-dark.png', $this->imageSet.'/splash-dark.png');
        $this->assertFileEquals($default.'/Contents.json', $this->imageSet.'/Contents.json');
    }

    /**
     * The set may be a symlink into artwork the app maintains elsewhere.
     * Clearing it takes out the link, never the directory it points at.
     */
    public function test_it_unlinks_a_symlinked_set_without_touching_the_target(): void
    {
        $target = $this->projectPath.'/shared-launch-image';
        File::makeDirectory($target, 0755, true);
        File::put($target.'/keep-me.png', 'artwork the app owns');

        File::deleteDirectory($this->imageSet);
        symlink($target, $this->imageSet);

        $this->writeSplash('splash.png');

        $this->installIosSplashScreen();

        $this->assertSame('artwork the app owns', File::get($target.'/keep-me.png'));

        $this->assertFalse(is_link($this->imageSet));
        $this->assertFileExists($this->imageSet.'/splash.png');
        $this->assertFileDoesNotExist($this->imageSet.'/keep-me.png');
    }
}
