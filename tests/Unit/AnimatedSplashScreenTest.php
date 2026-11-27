<?php

// No PHP test can run Compose, so this guards the Kotlin source itself.
it('plays an animated WebP splash and still decodes a still one with BitmapFactory', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt');

    expect($source)
        ->toContain('android.graphics.ImageDecoder.decodeDrawable(')
        ->toContain('as? android.graphics.drawable.AnimatedImageDrawable')
        ->toContain('(animation as? android.graphics.drawable.Animatable)?.start()')
        ->toContain('.decodeResource(resources, splashResourceId)');
});
