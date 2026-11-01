<?php

/**
 * The Android project must not write session cookies, CSRF tokens or auth
 * headers to logcat, in any build. Logcat is readable over adb, ends up in
 * bug reports, and crash and support tools send it off the device.
 *
 * Kotlin logs cookie names and LogRedaction's redacted headers instead of
 * values, and minified builds strip Log.v/d/i on top of that.
 */
function androidAppPath(string $path): string
{
    return dirname(__DIR__, 3).'/resources/androidstudio/app/'.$path;
}

/** @return array<string, string> file => contents of every Kotlin source */
function androidKotlinSources(): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(androidAppPath('src/main/java')));
    $sources = [];

    foreach ($files as $file) {
        if ($file->getExtension() === 'kt') {
            $sources[$file->getPathname()] = file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

/** @return list<string> every line that calls android.util.Log, prefixed with its file */
function androidKotlinLogLines(): array
{
    $lines = [];

    foreach (androidKotlinSources() as $file => $source) {
        foreach (explode("\n", $source) as $number => $line) {
            if (preg_match('/\bLog\.[vdiwe]\(/', $line)) {
                $lines[] = basename($file).':'.($number + 1).': '.trim($line);
            }
        }
    }

    return $lines;
}

it('never logs cookie values, CSRF tokens or raw header maps from Kotlin', function () {
    $lines = androidKotlinLogLines();
    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect($line)
            ->not->toMatch('/\$\{?(cookieValue|cookieLine|cookieString|csrfToken|token)\b/')
            ->not->toMatch('/\$\{?headers\b/')
            ->not->toMatch('/\$\{[\w.]*\.headers\}/');

        if (preg_match('/cookie|\$\{?key\b/i', $line)) {
            expect($line)->not->toMatch('/\$\{?value\b/');
        }
    }
});

it('no longer ships the request inspector, which logged every request header', function () {
    foreach (androidKotlinSources() as $source) {
        expect($source)->not->toContain('requestinspectorwebview');
    }

    expect(file_get_contents(androidAppPath('build.gradle.kts')))
        ->not->toContain('Android-Request-Inspector-WebView');
});

it('strips verbose, debug and info logging from minified builds', function () {
    expect(file_get_contents(androidAppPath('proguard-rules.pro')))->toMatch(
        '/-assumenosideeffects\s+class\s+android\.util\.Log\s*\{[^}]*\bv\(\.\.\.\);[^}]*\bd\(\.\.\.\);[^}]*\bi\(\.\.\.\);[^}]*\}/'
    );
});

it('logs only the name of each header PHP sends', function () {
    $source = file_get_contents(androidAppPath('src/main/cpp/php_bridge.c'));

    expect($source)
        ->not->toContain('"SAPI header: %s"')
        ->toContain('LOGI("SAPI header: %.*s", name_len, sapi_header->header);');
});

it('never logs cookie values from the native request setup', function () {
    preg_match_all('/\bLOG[IE]\((.*?)\);/s', file_get_contents(androidAppPath('src/main/cpp/PHP.c')), $calls);

    foreach ($calls[1] as $arguments) {
        expect($arguments)
            ->not->toMatch('/,\s*cookie_header\b/')
            ->not->toMatch('/,\s*val\b/')
            ->not->toMatch('/cookie token: %s/');
    }
});
