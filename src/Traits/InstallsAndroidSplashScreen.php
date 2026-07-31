<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;

trait InstallsAndroidSplashScreen
{
    use InstallsAppIcon;

    public function installAndroidSplashScreen(): void
    {
        $this->logToFile('Installing Android splash screen...');

        try {
            $lightSplashPath = public_path('splash.png');
            $darkSplashPath = public_path('splash-dark.png');

            $hasLightSplash = File::exists($lightSplashPath);
            $hasDarkSplash = File::exists($darkSplashPath);

            $this->logToFile('  Light splash (splash.png): '.($hasLightSplash ? 'found' : 'not found'));
            $this->logToFile('  Dark splash (splash-dark.png): '.($hasDarkSplash ? 'found' : 'not found'));

            if (! $hasLightSplash && ! $hasDarkSplash) {
                $this->logToFile('  No splash screens found, skipping');

                return;
            }

            $resDir = base_path('nativephp/android/app/src/main/res/');

            $sizes = [
                'mdpi' => [320, 480],
                'hdpi' => [480, 720],
                'xhdpi' => [640, 960],
                'xxhdpi' => [960, 1440],
                'xxxhdpi' => [1280, 1920],
            ];

            if ($hasLightSplash && $this->validateSplashImage($lightSplashPath)) {
                $this->logToFile('  Generating light splash variants...');
                foreach ($sizes as $density => $dimensions) {
                    try {
                        $dstDir = $resDir."drawable-{$density}";
                        File::ensureDirectoryExists($dstDir);

                        $dstPath = $dstDir.DIRECTORY_SEPARATOR.'splash.png';
                        $this->resizePng($lightSplashPath, $dstPath, $dimensions[0], $dimensions[1]);
                    } catch (\Exception $e) {
                        $this->logToFile("    Failed to generate $density: ".$e->getMessage());
                    }
                }
            }

            if ($hasDarkSplash && $this->validateSplashImage($darkSplashPath)) {
                $this->logToFile('  Generating dark splash variants...');
                foreach ($sizes as $density => $dimensions) {
                    try {
                        $dstDir = $resDir."drawable-night-{$density}";
                        File::ensureDirectoryExists($dstDir);

                        $dstPath = $dstDir.DIRECTORY_SEPARATOR.'splash.png';
                        $this->resizePng($darkSplashPath, $dstPath, $dimensions[0], $dimensions[1]);
                    } catch (\Exception $e) {
                        $this->logToFile("    Failed to generate night-$density: ".$e->getMessage());
                    }
                }
            }

            $this->logToFile('  Android splash screen installed');
        } catch (\Exception $e) {
            $this->logToFile('  ERROR: Splash screen processing failed: '.$e->getMessage());
            // Don't let splash screen processing block the build
        }
    }

    private function validateSplashImage(string $splashPath): bool
    {
        $image = @imagecreatefrompng($splashPath);
        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        imagedestroy($image);

        if ($width < 320 || $height < 480) {
            return false;
        }

        return true;
    }
}
