<?php

/**
 * Opcache's shared memory dies with the process, so the shells point
 * opcache.file_cache at a directory that outlives it. Release builds also stop
 * checking timestamps, which is only safe because every extraction of a new
 * bundle empties that directory before PHP runs against the new files.
 */
function opcacheSource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 3).'/resources/'.$path);
}

function opcacheFunctionBody(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    expect($start)->not->toBeFalse("{$signature} not found");

    $open = strpos($source, '{', $start);
    $depth = 0;

    for ($i = $open, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}' && --$depth === 0) {
            return substr($source, $open, $i - $open + 1);
        }
    }

    throw new RuntimeException("Unbalanced braces after {$signature}");
}

const IOS_INI = 'xcode/NativePHP/Bridge/PHPIni.swift';
const ANDROID_ENV = 'androidstudio/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt';

it('writes the opcache settings into php.ini', function (string $writer) {
    expect(opcacheSource($writer))
        ->toContain('opcache.file_cache=')
        ->toContain('opcache.file_cache_consistency_checks=1')
        ->toContain('opcache.memory_consumption=128')
        ->toContain('opcache.interned_strings_buffer=16')
        ->toContain('opcache.max_accelerated_files=4000')
        ->toContain('opcache.jit=disable')
        ->toContain('opcache.jit_buffer_size=0')
        ->toContain('opcache.validate_timestamps=0')
        ->toContain('opcache.validate_timestamps=1')
        ->toContain('opcache.revalidate_freq=0')
        ->not->toContain('opcache.file_cache_only=1');
})->with([IOS_INI, ANDROID_ENV]);

it('only turns timestamp checks off in release builds', function () {
    $ios = opcacheFunctionBody(opcacheSource(IOS_INI), 'static var isRelease: Bool');
    expect($ios)->toContain('#if DEBUG')
        ->and($ios)->toContain('"DEBUG"');

    $android = opcacheFunctionBody(opcacheSource(ANDROID_ENV), 'private fun opcacheSettings(fileCache: Boolean): String');
    expect($android)->toContain('FLAG_DEBUGGABLE')
        ->and($android)->toContain('isDebugBundle()');
});

it('reads the opt-out from bundle_meta.json', function () {
    expect(opcacheSource(IOS_INI))->toContain('bundleMeta?["opcache_file_cache"] as? Bool) ?? true')
        ->and(opcacheSource(ANDROID_ENV))->toContain('obj.optBoolean("opcache_file_cache", true)');
});

it('routes both iOS php.ini writers through PHPIni', function (string $caller) {
    expect(opcacheSource($caller))->toContain('PHPIni.write(caPath: caPath, fileCache: ')
        ->not->toContain('memory_limit=128M');
})->with([
    'xcode/NativePHP/Bridge/PersistentPHPRuntime.swift',
    'xcode/NativePHP/NativePHPApp.swift',
]);

it('empties the iOS file cache before PHP runs against a new bundle', function () {
    $source = opcacheSource('xcode/NativePHP/AppUpdateManager.swift');

    // Bundled extraction: before the old app goes and before the migrate.
    $copy = opcacheFunctionBody($source, 'private func copyBundledApp()');
    expect(strpos($copy, 'PHPIni.clearFileCache()'))->toBeLessThan(strpos($copy, 'runMigrationsAndClearCaches()'));

    // OTA payload: once the new files are in place and before the migrate.
    $install = opcacheFunctionBody($source, 'func installUpdate(from zipPath: String) -> Bool');
    $moved = strpos($install, 'try FileManager.default.moveItem(atPath: extractPath, toPath: appPath)');
    $cleared = strpos($install, 'PHPIni.clearFileCache()');
    expect($cleared)->toBeGreaterThan($moved)
        ->and($cleared)->toBeLessThan(strpos($install, 'runMigrationsAndClearCaches()'));

    // And again after every extraction, with the bootstrap cache.
    expect(opcacheFunctionBody($source, 'private func clearCompiledCaches()'))->toContain('PHPIni.clearFileCache()');
});

it('empties the Android file cache with the compiled caches after extraction', function () {
    $source = opcacheSource(ANDROID_ENV);

    expect(opcacheFunctionBody($source, 'private fun clearCompiledCaches()'))->toContain('clearOpcacheFileCache()');

    // clearCompiledCaches runs inside both extraction paths, and artisan only
    // runs after them.
    expect(opcacheFunctionBody($source, 'private fun extractLaravelBundleUnlocked(): Boolean'))->toContain('clearCompiledCaches()')
        ->and(opcacheFunctionBody($source, 'private fun applyPendingUpdate(laravelDir: File, otaMarkerFile: File): Boolean'))->toContain('clearCompiledCaches()');
});

it('logs whether the file cache is on at boot', function (string $bootstrap) {
    expect(file_get_contents(dirname(__DIR__, 3).'/bootstrap/'.$bootstrap))
        ->toContain("',file_cache='")
        ->toContain("',free_mb='");
})->with(['ios/persistent.php', 'android/persistent.php']);

/*
 * This PHP build computes a different opcache system id each time the embed
 * SAPI starts again in one process, so a cache written after artisan ran
 * lands in a directory the next launch never reads.
 */
it('only uses the file cache for the first PHP start-up on iOS', function () {
    expect(opcacheSource('xcode/NativePHP/NativePHPApp.swift'))
        ->toContain('PHPIni.write(caPath: caPath, fileCache: false)')
        ->and(substr_count(opcacheSource('xcode/NativePHP/NativePHPApp.swift'), "PHPIni.noteStartup()\n\n        argv.withUnsafeMutableBufferPointer { bufferPtr in\n            php_embed_init("))->toBe(2);

    expect(opcacheSource('xcode/NativePHP/Bridge/PersistentPHPRuntime.swift'))
        ->toContain('PHPIni.write(caPath: caPath, fileCache: !PHPIni.hasStarted)')
        ->toContain('PHPIni.noteStartup()');

    expect(opcacheFunctionBody(opcacheSource(IOS_INI), 'static func opcacheSettings(fileCache: Bool) -> String'))
        ->toContain('if fileCache, fileCacheEnabled');
});

it('leaves the file cache off on Android while artisan runs after an extraction', function () {
    $source = opcacheSource(ANDROID_ENV);

    expect(substr_count($source, 'setupEnvironment(forceCertRefresh = didExtract, opcacheFileCache = !didExtract)'))->toBe(2)
        ->and(opcacheFunctionBody($source, 'private fun opcacheSettings(fileCache: Boolean): String'))
        ->toContain('if (fileCache && readBundleMetadata().opcacheFileCache)');
});
