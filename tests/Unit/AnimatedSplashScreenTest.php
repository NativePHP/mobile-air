<?php

// No PHP test can run Compose or SwiftUI, so these guard the native sources themselves.
it('plays an animated WebP splash and still decodes a still one with BitmapFactory', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt');

    expect($source)
        ->toContain('android.graphics.ImageDecoder.decodeDrawable(')
        ->toContain('as? android.graphics.drawable.AnimatedImageDrawable')
        ->toContain('(animation as? android.graphics.drawable.Animatable)?.start()')
        ->toContain('.decodeResource(resources, splashResourceId)');
});

it('plays the data sets the iOS installer writes over the launch image', function () {
    $view = file_get_contents(dirname(__DIR__, 2).'/resources/xcode/NativePHP/SplashView.swift');
    $installer = file_get_contents(dirname(__DIR__, 2).'/src/Concerns/InstallsSplashScreen.php');

    foreach (['SplashAnimation', 'SplashAnimationDark'] as $dataSet) {
        expect($installer)->toContain("'{$dataSet}'")
            ->and($view)->toContain("NSDataAsset(name: \"{$dataSet}\")");
    }

    expect($view)
        ->toContain('Image("LaunchImage")')
        ->toContain('CGImageSourceCreateImageAtIndex(source, index, options)')
        ->toContain('kCGImagePropertyWebPLoopCount');
});
