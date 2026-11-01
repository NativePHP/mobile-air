<?php

/**
 * The `/_assets/<path>` handlers map a request path onto the app's public/
 * directory and serve the file. The path comes from the request URL, so a page
 * (or a crafted link) can put `..` in it — `/_assets/../.env`, or its
 * percent-encoded form `/_assets/..%2f.env`, which WebKit leaves as a `..`
 * segment. Unconfined that reads any file the app process can reach: `.env`,
 * the SQLite database, PHP source.
 *
 * The guard lives in the native handlers (Swift scheme handler + the Android
 * WebView client), which no PHP test can run — these assert both keep a
 * canonicalise-then-confine check, so neither platform can regress silently.
 */
function assetHandlerSource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/'.$path);
}

$ios = 'xcode/NativePHP/PHPSchemeHandler.swift';
$android = 'androidstudio/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt';

it('confines a resolved asset path to public/ before serving, on both platforms', function (string $path, string $resolver, string $rootName) {
    $source = assetHandlerSource($path);

    // The handler resolves the candidate (symlinks + ..) ...
    expect($source)->toContain($resolver)
        // ... derives the public/ root ...
        ->toContain($rootName)
        // ... and gates serving on staying inside it.
        ->toMatch('/hasPrefix\(.*'.preg_quote($rootName, '/').'|startsWith\('.preg_quote($rootName, '/').'/');
})->with([
    'iOS' => ['xcode/NativePHP/PHPSchemeHandler.swift', 'resolvingSymlinksInPath()', 'publicRoot'],
    'Android' => ['androidstudio/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt', 'canonicalPath', 'publicRoot'],
]);

it('serves the asset only when it resolves inside public/ (iOS)', function () use ($ios) {
    $source = assetHandlerSource($ios);

    // The existing fileExists serve is now gated on the containment flag, so a
    // path that escapes public/ is never served.
    expect($source)->toContain('if withinPublic, FileManager.default.fileExists(atPath: localPath)');
});

it('checks containment before existence for every candidate (Android)', function () use ($android) {
    $source = assetHandlerSource($android);

    expect($source)->toContain('withinPublic(it) && File(it).exists()');
});
