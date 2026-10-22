<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\File;

trait InstallsAndroidSplashScreen
{
    use InstallsAppIcon;

    public function installAndroidSplashScreen(): void
    {
        $this->logToFile('Installing Android splash screen...');

        try {
            // Accept either splash.png or splash.webp as source (webp takes
            // precedence when both exist, since it's the smaller of the two).
            $lightSplashPath = $this->locateSplashSource('splash');
            $darkSplashPath = $this->locateSplashSource('splash-dark');

            $hasLightSplash = $lightSplashPath !== null;
            $hasDarkSplash = $darkSplashPath !== null;

            $this->logToFile('  Light splash: '.($hasLightSplash ? basename($lightSplashPath) : 'not found'));
            $this->logToFile('  Dark splash: '.($hasDarkSplash ? basename($darkSplashPath) : 'not found'));

            if (! $hasLightSplash && ! $hasDarkSplash) {
                $this->logToFile('  No splash screens found, skipping');

                return;
            }

            $resDir = base_path('nativephp/android/app/src/main/res/');
            $format = $this->androidImageFormat();

            $sizes = [
                'mdpi' => [320, 480],
                'hdpi' => [480, 720],
                'xhdpi' => [640, 960],
                'xxhdpi' => [960, 1440],
                'xxxhdpi' => [1280, 1920],
            ];

            if ($hasLightSplash && $this->validateSplashImage($lightSplashPath)) {
                $this->logToFile("  Generating light splash variants (.$format)...");
                foreach ($sizes as $density => $dimensions) {
                    try {
                        $dstDir = $resDir."drawable-{$density}";
                        File::ensureDirectoryExists($dstDir);

                        $this->cleanStaleSplash($dstDir, $format);

                        $dstPath = $dstDir.DIRECTORY_SEPARATOR.'splash.'.$format;
                        $this->resizeImage($lightSplashPath, $dstPath, $dimensions[0], $dimensions[1], $format);
                    } catch (\Exception $e) {
                        $this->logToFile("    Failed to generate $density: ".$e->getMessage());
                    }
                }
            }

            if ($hasDarkSplash && $this->validateSplashImage($darkSplashPath)) {
                $this->logToFile("  Generating dark splash variants (.$format)...");
                foreach ($sizes as $density => $dimensions) {
                    try {
                        $dstDir = $resDir."drawable-night-{$density}";
                        File::ensureDirectoryExists($dstDir);

                        $this->cleanStaleSplash($dstDir, $format);

                        $dstPath = $dstDir.DIRECTORY_SEPARATOR.'splash.'.$format;
                        $this->resizeImage($darkSplashPath, $dstPath, $dimensions[0], $dimensions[1], $format);
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

    /**
     * Look for `public/{name}.webp` first, then `public/{name}.png`.
     */
    private function locateSplashSource(string $name): ?string
    {
        $webp = public_path($name.'.webp');
        if (File::exists($webp) && function_exists('imagecreatefromwebp')) {
            return $webp;
        }

        $png = public_path($name.'.png');
        if (File::exists($png)) {
            return $png;
        }

        return null;
    }

    /**
     * Remove a splash resource of the opposite extension so AAPT does not see
     * two entries for the same resource name in the same folder.
     */
    private function cleanStaleSplash(string $dir, string $keepFormat): void
    {
        $stale = $dir.DIRECTORY_SEPARATOR.'splash.'.($keepFormat === 'png' ? 'webp' : 'png');
        if (File::exists($stale)) {
            File::delete($stale);
        }
    }

    private function validateSplashImage(string $splashPath): bool
    {
        $ext = strtolower(pathinfo($splashPath, PATHINFO_EXTENSION));

        if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($splashPath);
        } else {
            $image = @imagecreatefrompng($splashPath);
        }

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
