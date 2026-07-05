<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;

trait InstallsSplashScreen
{
    public function installIosSplashScreen()
    {
        // Define supported splash screen variants
        $splashVariants = [
            'splash.png' => ['filename' => 'splash.png', 'idiom' => 'universal'],
            'splash-dark.png' => ['filename' => 'splash-dark.png', 'idiom' => 'universal', 'appearances' => [['appearance' => 'luminosity', 'value' => 'dark']]],
            'splash@2x.png' => ['filename' => 'splash@2x.png', 'idiom' => 'universal', 'scale' => '2x'],
            'splash-dark@2x.png' => ['filename' => 'splash-dark@2x.png', 'idiom' => 'universal', 'scale' => '2x', 'appearances' => [['appearance' => 'luminosity', 'value' => 'dark']]],
            'splash@3x.png' => ['filename' => 'splash@3x.png', 'idiom' => 'universal', 'scale' => '3x'],
            'splash-dark@3x.png' => ['filename' => 'splash-dark@3x.png', 'idiom' => 'universal', 'scale' => '3x', 'appearances' => [['appearance' => 'luminosity', 'value' => 'dark']]],
        ];

        // Xcode's asset compiler rejects Image Sets that mix entries with no
        // `scale` key ("Any" scale) and entries with specific scales (1x/2x/3x)
        // when the "Any" entries reference bitmap content. If the project ships
        // any scaled variants, drop the unscaled ones so the generated
        // Contents.json doesn't trigger:
        //   error: Image set has a child with bitmap content and the "Any"
        //   scale. The image set also has children with specific scales...
        $hasScaledVariants = false;
        foreach (['splash@2x.png', 'splash-dark@2x.png', 'splash@3x.png', 'splash-dark@3x.png'] as $scaledFilename) {
            if (File::exists(public_path($scaledFilename))) {
                $hasScaledVariants = true;
                break;
            }
        }
        if ($hasScaledVariants) {
            unset($splashVariants['splash.png'], $splashVariants['splash-dark.png']);
        }

        $foundVariants = [];
        $images = [];

        // Check which splash screen variants exist
        foreach ($splashVariants as $filename => $config) {
            $splashPath = public_path($filename);
            if (File::exists($splashPath)) {
                if ($this->validateIosSplashScreen($splashPath, $filename)) {
                    $foundVariants[$filename] = $splashPath;
                    $images[] = array_filter($config); // Remove null values
                }
            }
        }

        if (empty($foundVariants)) {
            return;
        }

        // Ensure the LaunchImage asset catalog exists
        $launchImageDir = base_path('nativephp/ios/NativePHP/Assets.xcassets/LaunchImage.imageset');
        File::ensureDirectoryExists($launchImageDir);

        // Create Contents.json for LaunchImage with all variants
        $contentsJson = [
            'images' => $images,
            'info' => [
                'author' => 'xcode',
                'version' => 1,
            ],
            'properties' => [
                'pre-rendered' => true,
            ],
        ];

        File::put($launchImageDir.'/Contents.json', json_encode($contentsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($foundVariants as $filename => $sourcePath) {
            @copy($sourcePath, $launchImageDir.'/'.$filename);
        }
    }

    private function validateIosSplashScreen(string $splashPath, string $filename = 'splash.png'): bool
    {
        // Check if the file is a valid PNG image
        if (! $image = @imagecreatefrompng($splashPath)) {
            return false;
        }

        // Get image dimensions
        $width = imagesx($image);
        $height = imagesy($image);

        // Determine expected scale factor from filename
        $expectedScale = 1;
        if (str_contains($filename, '@2x')) {
            $expectedScale = 2;
        } elseif (str_contains($filename, '@3x')) {
            $expectedScale = 3;
        }

        // Define base minimum dimensions (for 1x scale)
        $baseMinWidth = 375;
        $baseMinHeight = 667;

        // Scale expected dimensions
        $expectedMinWidth = $baseMinWidth * $expectedScale;
        $expectedMinHeight = $baseMinHeight * $expectedScale;

        if ($width < $expectedMinWidth || $height < $expectedMinHeight) {
            imagedestroy($image);

            return false;
        }

        // Recommend common iOS screen sizes
        $commonSizes = [
            // iPhone sizes (portrait)
            [375, 667],   // iPhone 8, SE 2nd/3rd gen
            [414, 896],   // iPhone XR, 11
            [375, 812],   // iPhone X, XS, 11 Pro, 12 mini, 13 mini
            [414, 896],   // iPhone XS Max, 11 Pro Max
            [390, 844],   // iPhone 12, 12 Pro, 13, 13 Pro, 14
            [428, 926],   // iPhone 12 Pro Max, 13 Pro Max, 14 Plus
            [393, 852],   // iPhone 14 Pro
            [430, 932],   // iPhone 14 Pro Max
            // iPad sizes (portrait)
            [768, 1024],  // iPad, iPad 2, iPad mini
            [834, 1112],  // iPad Air, iPad Pro 10.5"
            [820, 1180],  // iPad Air 4th/5th gen
            [1024, 1366], // iPad Pro 12.9"
        ];

        // Scale common sizes by expected scale factor
        $scaledCommonSizes = array_map(function ($size) use ($expectedScale) {
            return [$size[0] * $expectedScale, $size[1] * $expectedScale];
        }, $commonSizes);

        // Check if dimensions match any common size (with some tolerance)
        $isCommonSize = false;
        foreach ($scaledCommonSizes as [$w, $h]) {
            if (abs($width - $w) <= 10 && abs($height - $h) <= 10) {
                $isCommonSize = true;
                break;
            }
        }

        imagedestroy($image);

        return true;
    }
}
