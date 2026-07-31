<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;

trait InstallsAppIcon
{
    public function installIosIcon()
    {
        $iconPath = public_path('icon.png');

        if (! File::exists($iconPath)) {
            return;
        }

        if ($this->validateIosIcon($iconPath)) {
            @copy($iconPath, base_path('nativephp/ios/NativePHP/Assets.xcassets/AppIcon.appiconset/icon.png'));
        }
    }

    private function validateIosIcon(string $iconPath): bool
    {
        if (! $image = @imagecreatefrompng($iconPath)) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width !== $height) {
            imagedestroy($image);

            return false;
        }

        if ($width < 1024) {
            imagedestroy($image);

            return false;
        }

        $hasTransparency = false;

        if (imageistruecolor($image) && imagecolortransparent($image) == -1) {
            $samplePoints = [
                [0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1],
                [$width / 2, $height / 2],
                [$width / 4, $height / 4], [$width * 3 / 4, $height * 3 / 4],
            ];

            foreach ($samplePoints as [$x, $y]) {
                $rgba = imagecolorat($image, (int) $x, (int) $y);
                $alpha = ($rgba & 0x7F000000) >> 24;

                if ($alpha > 0) {
                    $hasTransparency = true;
                    break;
                }
            }
        }

        if ($hasTransparency) {
            imagedestroy($image);

            return false;
        }

        imagedestroy($image);

        return true;
    }

    public function installAndroidIcon(): void
    {
        $this->logToFile('Installing Android icon...');
        $iconPath = public_path('icon.png');

        if (! File::exists($iconPath)) {
            $this->logToFile('  No icon.png found at public/icon.png, skipping');

            return;
        }

        $this->logToFile("  Source icon: $iconPath");

        $resDir = base_path('nativephp/android/app/src/main/res/');

        $sizes = [
            'mipmap-mdpi' => 48,
            'mipmap-hdpi' => 72,
            'mipmap-xhdpi' => 96,
            'mipmap-xxhdpi' => 144,
            'mipmap-xxxhdpi' => 192,
        ];

        $adaptiveSizes = [
            'mipmap-mdpi' => 108,
            'mipmap-hdpi' => 162,
            'mipmap-xhdpi' => 216,
            'mipmap-xxhdpi' => 324,
            'mipmap-xxxhdpi' => 432,
        ];

        $targets = [
            'ic_launcher.png',
            'ic_launcher_round.png',
            'ic_launcher_foreground.png',
        ];

        $this->logToFile('  Generating icon sizes: '.implode(', ', array_keys($sizes)));

        foreach ($sizes as $folder => $size) {
            $dstDir = $resDir.$folder;
            File::ensureDirectoryExists($dstDir);

            foreach ($targets as $filename) {
                $dstPath = $dstDir.'/'.$filename;

                $webpPath = str_replace('.png', '.webp', $dstPath);
                if (File::exists($webpPath)) {
                    File::delete($webpPath);
                }

                $targetSize = ($filename === 'ic_launcher_foreground.png') ? $adaptiveSizes[$folder] : $size;

                $this->resizePng($iconPath, $dstPath, $targetSize, $targetSize);
            }
        }

        $this->logToFile('  Android icon installed');
    }

    private function resizePng(string $src, string $dst, int $width, int $height): void
    {
        $srcImage = imagecreatefrompng($src);
        $srcWidth = imagesx($srcImage);
        $srcHeight = imagesy($srcImage);

        $resized = imagecreatetruecolor($width, $height);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        $isAndroidForeground = str_contains($dst, 'ic_launcher_foreground');
        // Android adaptive icons: 108dp canvas with 66dp safe zone (61%)
        // Use 0.55 to ensure icon stays within safe zone with padding for all mask shapes
        $scaleFactor = $isAndroidForeground ? 0.69 : 1.0;

        $srcRatio = $srcWidth / $srcHeight;
        $dstRatio = $width / $height;

        if ($srcRatio > $dstRatio) {
            $newWidth = (int) ($width * $scaleFactor);
            $newHeight = (int) (($width * $scaleFactor) / $srcRatio);
            $offsetX = (int) (($width - $newWidth) / 2);
            $offsetY = (int) (($height - $newHeight) / 2);
        } else {
            $newWidth = (int) (($height * $scaleFactor) * $srcRatio);
            $newHeight = (int) ($height * $scaleFactor);
            $offsetX = (int) (($width - $newWidth) / 2);
            $offsetY = (int) (($height - $newHeight) / 2);
        }

        imagecopyresampled(
            $resized, $srcImage,
            $offsetX, $offsetY, 0, 0,
            $newWidth, $newHeight,
            $srcWidth, $srcHeight
        );

        imagepng($resized, $dst, 0);
        imagedestroy($resized);
        imagedestroy($srcImage);
    }
}
