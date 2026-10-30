<?php

/**
 * MainActivity handles font size and display size changes itself. Without
 * fontScale and density in configChanges, Android destroys and recreates the
 * activity when the user changes either setting, and the app restarts on the
 * first screen of its stack instead of staying where the user was (#631).
 */
function mainActivityConfigChanges(): array
{
    $manifest = simplexml_load_file(dirname(__DIR__, 3).'/resources/androidstudio/app/src/main/AndroidManifest.xml');
    $manifest->registerXPathNamespace('android', 'http://schemas.android.com/apk/res/android');

    $activities = $manifest->xpath('//activity[@android:name=".ui.MainActivity"]');
    expect($activities)->toHaveCount(1);

    return explode('|', (string) $activities[0]->attributes('http://schemas.android.com/apk/res/android')->configChanges);
}

it('keeps MainActivity alive across font size and display size changes', function () {
    expect(mainActivityConfigChanges())->toContain('fontScale', 'density');
});

it('still handles the changes it handled before', function () {
    expect(mainActivityConfigChanges())->toContain('uiMode', 'colorMode', 'orientation', 'screenSize');
});
