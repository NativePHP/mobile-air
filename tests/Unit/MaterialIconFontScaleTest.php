<?php

// No PHP test can run Compose, so this guards the Kotlin source itself.
it('sizes the MaterialIcon glyph from its dp box, not the system font scale', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/IconHelper.kt');

    expect($source)
        ->toContain('fontSize = with(LocalDensity.current) { size.toSp() }')
        ->not->toContain('size.value.sp');
});
