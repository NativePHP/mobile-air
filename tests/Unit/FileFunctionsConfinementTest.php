<?php

/**
 * File.Move / File.Copy are exported in the JS bridge, so a web view page can
 * call them through /_native/api/call. Left open that is a file-exfil primitive
 * (copy a secret into public/, read it back at /_assets/…) and a clobber/DoS
 * primitive. The fix confines paths in the native implementations, which no PHP
 * test can execute — these assert the Swift and Kotlin twins keep the guard, so
 * one platform can't regress without the other.
 */
function fileFunctionsSource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/'.$path);
}

$ios = 'xcode/NativePHP/Bridge/Functions/FileFunctions.swift';
$android = 'androidstudio/app/src/main/java/com/nativephp/mobile/bridge/functions/FileFunctions.kt';

it('confines both Move and Copy before the file operation on both platforms', function (string $path, string $callMarker, array $ops) {
    $source = fileFunctionsSource($path);

    // The confinement runs in each of Move and Copy.
    expect(substr_count($source, $callMarker))->toBe(2);

    // And it runs before the file is touched, in both.
    foreach ($ops as $op) {
        $guardAt = strpos($source, $callMarker);
        $opAt = strpos($source, $op);
        expect($guardAt)->toBeLessThan($opAt, "{$op} runs before the confinement guard");
    }
})->with([
    'iOS' => [$ios, 'confinementError(from: from, to: to)', ['moveItem', 'copyItem']],
    'Android' => [$android, 'confinementError(activity.applicationContext, from, to)', ['renameTo', 'copyTo']],
]);

it('canonicalises paths before checking them, so .. and symlinks cannot escape', function (string $path, string $needle) {
    expect(fileFunctionsSource($path))->toContain($needle);
})->with([
    'iOS resolves symlinks' => [$ios, 'resolvingSymlinksInPath()'],
    'Android resolves via canonicalPath' => [$android, 'canonicalPath'],
]);

it('confines the destination to app storage on both platforms', function (string $path, string $needle) {
    expect(fileFunctionsSource($path))->toContain($needle);
})->with([
    'iOS' => [$ios, 'storageAppRoot'],
    'Android' => [$android, 'storageAppRoot'],
]);

it('anchors the storage root to the real storage_path, not the ephemeral app extract', function (string $path, string $needle) {
    // storage_path() on both platforms points outside the swappable app tree
    // (iOS: Application Support via LARAVEL_STORAGE_PATH; Android:
    // persisted_data/storage). Anchoring the destination root anywhere under the
    // extracted app would both reject legitimate storage_path('app') moves and
    // write to storage that an update wipes. Pin the real source of truth.
    expect(fileFunctionsSource($path))->toContain($needle);
})->with([
    'iOS reads LARAVEL_STORAGE_PATH' => [$ios, 'getenv("LARAVEL_STORAGE_PATH")'],
    'Android uses persisted_data/storage' => [$android, 'persisted_data/storage/app'],
]);
