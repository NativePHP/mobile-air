<?php

/**
 * Android ran two queue workers in one process, and the second segfaulted in
 * native_worker_artisan. native:watch leaves reload_signal.json in app storage,
 * and the hot reload watcher read it as a new save on every cold start, so it
 * rebooted the persistent runtime under a native session that hadn't drawn
 * yet. While that reboot waited out its shutdown timeout, the deferred worker
 * start found no worker and started one, then the reboot started another. The
 * worker's PHP context is thread-local, so the second thread ran PHP without one.
 */
function runtimeRebootSource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 3).'/resources/androidstudio/app/src/main/'.$path);
}

function runtimeRebootBody(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    expect($start)->not->toBeFalse("{$signature} not found");

    $open = strpos($source, '{', $start + strlen($signature) - 1);
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

it('ignores reload signals already on disk when the hot reload watcher starts', function () {
    $watcher = runtimeRebootBody(
        runtimeRebootSource('java/com/nativephp/mobile/ui/MainActivity.kt'),
        'private fun startHotReloadWatcher() {',
    );

    expect($watcher)
        ->toContain('var lastModified: Long = reloadFile.lastModified()')
        ->toContain('var lastRestartModified: Long = restartFile.lastModified()');
});

it('does not start the deferred queue worker while a runtime reboot is in flight', function () {
    $activity = runtimeRebootSource('java/com/nativephp/mobile/ui/MainActivity.kt');

    $deferredStart = substr($activity, strrpos(
        substr($activity, 0, strpos($activity, 'Starting deferred background queue worker')),
        'postDelayed({',
    ));
    $deferredStart = runtimeRebootBody($deferredStart, 'postDelayed({');

    expect($deferredStart)
        ->toContain('synchronized(queueWorkerLock) {')
        ->toContain('!rebootingRuntime');
});

it('keeps the reboot flag up from before the old worker stops until the new one starts', function () {
    $reboot = runtimeRebootBody(
        runtimeRebootSource('java/com/nativephp/mobile/ui/MainActivity.kt'),
        'private fun rebootPersistentRuntime() {',
    );

    $flagged = strpos($reboot, 'rebootingRuntime = true');
    $stopped = strpos($reboot, 'worker?.stop()');
    $shutdown = strpos($reboot, 'phpBridge.shutdownPersistentRuntime()');
    $started = strpos($reboot, 'queueWorker = PHPQueueWorker(phpBridge)');
    $cleared = strpos($reboot, 'rebootingRuntime = false');

    expect($flagged)->not->toBeFalse()
        ->and($flagged)->toBeLessThan($stopped)
        ->and($stopped)->toBeLessThan($shutdown)
        ->and($shutdown)->toBeLessThan($started)
        ->and($started)->toBeLessThan($cleared)
        ->and(substr_count($reboot, 'synchronized(queueWorkerLock)'))->toBe(2);
});

it('runs worker PHP only on the thread that booted the worker', function (string $function) {
    $body = runtimeRebootBody(runtimeRebootSource('cpp/php_bridge.c'), $function);

    expect($body)->toContain('pthread_equal(worker_thread, pthread_self())');
})->with([
    'boot' => 'JNIEXPORT jint JNICALL native_worker_boot(',
    'artisan' => 'JNIEXPORT jstring JNICALL native_worker_artisan(',
    'shutdown' => 'JNIEXPORT void JNICALL native_worker_shutdown(',
]);

it('refuses to boot a second worker while another thread owns the first', function () {
    $boot = runtimeRebootBody(runtimeRebootSource('cpp/php_bridge.c'), 'JNIEXPORT jint JNICALL native_worker_boot(');

    $refusal = substr($boot, strpos($boot, 'if (!pthread_equal(worker_thread, pthread_self()))'));
    $refusal = runtimeRebootBody($refusal, 'if (!pthread_equal(worker_thread, pthread_self())) {');

    expect($refusal)->toContain('return -1;')
        ->and($boot)->toContain('worker_thread = pthread_self();');
});
