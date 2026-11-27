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

            foreach (['' => $lightSplashPath, 'night-' => $darkSplashPath] as $qualifier => $sourcePath) {
                if ($sourcePath === null || ! $this->validateSplashImage($sourcePath)) {
                    continue;
                }

                if ($this->animatedWebpSize($sourcePath) !== null) {
                    $this->installAnimatedSplash($sourcePath, $resDir.'drawable-'.$qualifier, array_keys($sizes));

                    continue;
                }

                $this->logToFile('  Generating '.($qualifier === '' ? 'light' : 'dark')." splash variants (.$format)...");
                File::delete($resDir."drawable-{$qualifier}nodpi".DIRECTORY_SEPARATOR.'splash.webp');

                foreach ($sizes as $density => $dimensions) {
                    try {
                        $dstDir = $resDir."drawable-{$qualifier}{$density}";
                        File::ensureDirectoryExists($dstDir);

                        $this->cleanStaleSplash($dstDir, $format);

                        $dstPath = $dstDir.DIRECTORY_SEPARATOR.'splash.'.$format;
                        $this->resizeImage($sourcePath, $dstPath, $dimensions[0], $dimensions[1], $format);
                    } catch (\Exception $e) {
                        $this->logToFile("    Failed to generate {$qualifier}{$density}: ".$e->getMessage());
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
        if (File::exists($webp) && (function_exists('imagecreatefromwebp') || $this->animatedWebpSize($webp) !== null)) {
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

    /**
     * GD cannot decode an animated WebP, so it ships unscaled in drawable-nodpi
     * and the per-density copies an earlier build wrote are removed.
     */
    private function installAnimatedSplash(string $sourcePath, string $drawablePrefix, array $densities): void
    {
        $this->logToFile('  Copying animated '.basename($sourcePath).' to '.basename($drawablePrefix).'nodpi...');

        foreach ($densities as $density) {
            File::delete([
                $drawablePrefix.$density.DIRECTORY_SEPARATOR.'splash.png',
                $drawablePrefix.$density.DIRECTORY_SEPARATOR.'splash.webp',
            ]);
        }

        File::ensureDirectoryExists($drawablePrefix.'nodpi');
        File::copy($sourcePath, $drawablePrefix.'nodpi'.DIRECTORY_SEPARATOR.'splash.webp');
    }

    /**
     * Canvas size from the VP8X header of an animated WebP, or null for any other file.
     */
    private function animatedWebpSize(string $path): ?array
    {
        $header = @file_get_contents($path, false, null, 0, 30);

        if (! is_string($header) || strlen($header) < 30
            || ! str_starts_with($header, 'RIFF')
            || substr($header, 8, 8) !== 'WEBPVP8X'
            || (ord($header[20]) & 0x02) === 0) {
            return null;
        }

        return [
            unpack('V', substr($header, 24, 3)."\0")[1] + 1,
            unpack('V', substr($header, 27, 3)."\0")[1] + 1,
        ];
    }

    private function validateSplashImage(string $splashPath): bool
    {
        $animatedSize = $this->animatedWebpSize($splashPath);
        if ($animatedSize !== null) {
            return $animatedSize[0] >= 320 && $animatedSize[1] >= 480;
        }

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
