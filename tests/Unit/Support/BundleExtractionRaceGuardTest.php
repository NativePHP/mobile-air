<?php

/**
 * Startup race, confirmed on device: LaravelEnvironment.initialize() and
 * initializeForBackground() check isPersistentMode() only BEFORE acquiring
 * extractionLock, then extract unconditionally inside it. In DEBUG,
 * extractLaravelBundleUnlocked() ALWAYS re-extracts — it rm -rf's the whole
 * laravel/ tree (vendor included) and re-unzips.
 *
 * PHPBridge.bootPersistentRuntime() holds that same extractionLock and only
 * flips the process-global persistent flags while holding it. So a queued
 * initializer (WorkManager worker, or a re-created activity's init thread)
 * can read isPersistentMode() == false, park on the lock while the booting
 * initializer extracts, runs artisan and boots the persistent runtime, then
 * wake up holding the lock and delete vendor/ UNDER the live interpreter.
 * Device evidence: PHP include of flysystem's PortableVisibilityConverter
 * failed at 12:50:30.192 UTC; the file's mtime is 12:50:30.676 — rewritten
 * ~485ms AFTER the failure. The file exists in the APK and on disk; it was
 * deleted mid-execution and re-created by the racing unzip.
 *
 * The contract these tests guard: the persistent-runtime check must be
 * AUTHORITATIVE INSIDE the shared extraction lock, before any extraction or
 * artisan work. The outer check stays, but only as a fast path.
 */
function laravelEnvironmentKotlinSource(): string
{
    $path = dirname(__DIR__, 3).'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt';

    return file_get_contents($path);
}

function extractionGuardKotlinSource(): string
{
    $path = dirname(__DIR__, 3).'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/ExtractionGuard.kt';
    expect($path)->toBeFile('ExtractionGuard.kt is missing — the guarded lock helper must exist');

    return file_get_contents($path);
}

function phpBridgeKotlinSource(): string
{
    return file_get_contents(dirname(__DIR__, 3).'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt');
}

function kotlinFunctionBody(string $source, string $signature): string
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

it('re-checks the persistent runtime inside the extraction lock in initialize()', function () {
    $body = kotlinFunctionBody(laravelEnvironmentKotlinSource(), 'fun initialize()');

    $guardPos = strpos($body, 'runUnderLockUnlessPersistent(');
    expect($guardPos)->not->toBeFalse(
        'initialize() must run its locked startup work through runUnderLockUnlessPersistent()'
    );

    $checkPos = strpos($body, 'phpBridge.isPersistentMode()', $guardPos);
    expect($checkPos)->not->toBeFalse(
        'the authoritative isPersistentMode() check must live inside the guarded lock call, not only before it'
    );

    $extractPos = strpos($body, 'extractLaravelBundleUnlocked()');
    expect($extractPos)->not->toBeFalse();
    expect($extractPos)->toBeGreaterThan($checkPos, 'extraction may only run inside the re-checked guarded region');
});

it('re-checks the persistent runtime inside the extraction lock in initializeForBackground()', function () {
    $body = kotlinFunctionBody(laravelEnvironmentKotlinSource(), 'fun initializeForBackground()');

    $guardPos = strpos($body, 'runUnderLockUnlessPersistent(');
    expect($guardPos)->not->toBeFalse(
        'initializeForBackground() must run its locked startup work through runUnderLockUnlessPersistent()'
    );

    $checkPos = strpos($body, 'phpBridge.isPersistentMode()', $guardPos);
    expect($checkPos)->not->toBeFalse(
        'the authoritative isPersistentMode() check must live inside the guarded lock call, not only before it'
    );

    $extractPos = strpos($body, 'extractLaravelBundleUnlocked()');
    expect($extractPos)->not->toBeFalse();
    expect($extractPos)->toBeGreaterThan($checkPos, 'extraction may only run inside the re-checked guarded region');
});

it('keeps the outer process-reuse check as a cheap fast path in both initializers', function (string $signature) {
    $body = kotlinFunctionBody(laravelEnvironmentKotlinSource(), $signature);

    $fastPathPos = strpos($body, 'if (phpBridge.isPersistentMode())');
    expect($fastPathPos)->not->toBeFalse("{$signature} lost its pre-lock fast-path skip");

    $guardPos = strpos($body, 'runUnderLockUnlessPersistent(');
    expect($guardPos)->not->toBeFalse();
    expect($fastPathPos)->toBeLessThan($guardPos, "{$signature} fast path must stay before the lock");
})->with([
    'fun initialize()',
    'fun initializeForBackground()',
]);

it('performs the persistent-runtime check only while holding the lock, before running the prepare block', function () {
    $source = extractionGuardKotlinSource();

    $lockPos = strpos($source, 'lock.withLock');
    $checkPos = strpos($source, 'isPersistentRuntime()');
    $preparePos = strpos($source, 'prepare()');

    expect($lockPos)->not->toBeFalse('the guard must take the lock');
    expect($checkPos)->not->toBeFalse('the guard must consult the persistent-runtime flag');
    expect($preparePos)->not->toBeFalse('the guard must run the prepare block');

    expect($checkPos)->toBeGreaterThan($lockPos, 'the check must happen INSIDE the lock');
    expect($preparePos)->toBeGreaterThan($checkPos, 'prepare may only run after the inside-lock check');
});

it('boots the persistent runtime while holding the shared extraction lock (flags settle before release)', function () {
    $source = phpBridgeKotlinSource();
    $body = kotlinFunctionBody($source, 'fun bootPersistentRuntime(): Boolean');

    // The lock is taken in the expression signature, before the body brace.
    $start = strpos($source, 'fun bootPersistentRuntime(): Boolean');
    $signature = substr($source, $start, strpos($source, '{', $start) - $start + 1);
    expect(strpos($signature, 'LaravelEnvironment.extractionLock.withLock'))->not->toBeFalse(
        'bootPersistentRuntime must share LaravelEnvironment.extractionLock — the inside-lock recheck relies on it'
    );
    expect(strpos($body, 'persistentBooted = true'))->not->toBeFalse();
    expect(strpos($body, 'persistentMode = true'))->not->toBeFalse();
});
