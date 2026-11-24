<?php

use Illuminate\Bus\Batch;
use Illuminate\Bus\Events\BatchDispatched;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Native\Mobile\Runtime;
use Native\Mobile\Testing\FakeBridge;

function queueWorkerResource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 3).'/resources/'.$path);
}

afterEach(function () {
    FakeBridge::disable();
    config()->set('nativephp-internal.running', false);
    config()->set('nativephp.runtime.queue_worker', 'lazy');
});

it('normalizes queue_worker config values into lazy, eager, or disabled', function (mixed $input, string $expected) {
    expect(Runtime::normalizeQueueWorkerMode($input))->toBe($expected);
})->with([
    'default lazy string' => ['lazy', 'lazy'],
    'null falls back to lazy' => [null, 'lazy'],
    'unknown string falls back to lazy' => ['anything-else', 'lazy'],
    'bool false' => [false, 'disabled'],
    'int 0' => [0, 'disabled'],
    'string disabled' => ['disabled', 'disabled'],
    'string false' => ['FALSE', 'disabled'],
    'string off' => [' off ', 'disabled'],
    'string none' => ['none', 'disabled'],
    'bool true' => [true, 'eager'],
    'int 1' => [1, 'eager'],
    'string eager' => ['eager', 'eager'],
    'string true' => ['true', 'eager'],
    'string always' => ['always', 'eager'],
]);

it('defaults Runtime::queueWorkerMode() to lazy', function () {
    config()->set('nativephp.runtime.queue_worker', 'lazy');
    expect(Runtime::queueWorkerMode())->toBe('lazy');

    config()->set('nativephp.runtime.queue_worker', false);
    expect(Runtime::queueWorkerMode())->toBe('disabled');

    config()->set('nativephp.runtime.queue_worker', true);
    expect(Runtime::queueWorkerMode())->toBe('eager');
});

it('calls Queue.Wake on JobQueued when running on device unless disabled', function (mixed $mode, bool $shouldWake) {
    $bridge = FakeBridge::enable();
    config()->set('nativephp-internal.running', true);
    config()->set('nativephp.runtime.queue_worker', $mode);

    Event::dispatch(new JobQueued('database', 'default', '1', 'App\\Jobs\\SyncJob', '{}', null));

    $calls = array_values(array_filter(
        $bridge->calls,
        fn (array $call) => $call['method'] === 'Queue.Wake',
    ));

    expect($calls)->toHaveCount($shouldWake ? 1 : 0);
})->with([
    'lazy mode wakes worker' => ['lazy', true],
    'eager mode wakes sleeping worker' => ['eager', true],
    'disabled string does not wake' => ['disabled', false],
    'false bool does not wake' => [false, false],
]);

it('does not call Queue.Wake when not running inside NativePHP', function () {
    $bridge = FakeBridge::enable();
    config()->set('nativephp-internal.running', false);
    config()->set('nativephp.runtime.queue_worker', 'lazy');

    Event::dispatch(new JobQueued('database', 'default', '1', 'App\\Jobs\\SyncJob', '{}', null));

    expect($bridge->calls)->toBeEmpty();
});

it('wakes the queue worker on BatchDispatched for non-sync connections only', function (string $connection, bool $shouldWake) {
    $bridge = FakeBridge::enable();
    config()->set('nativephp-internal.running', true);
    config()->set('nativephp.runtime.queue_worker', 'lazy');
    config()->set('queue.connections.sync.driver', 'sync');
    config()->set('queue.connections.database.driver', 'database');

    $batch = (new ReflectionClass(Batch::class))->newInstanceWithoutConstructor();
    $batch->options = ['connection' => $connection];

    Event::dispatch(new BatchDispatched($batch));

    $calls = array_values(array_filter(
        $bridge->calls,
        fn (array $call) => $call['method'] === 'Queue.Wake',
    ));

    expect($calls)->toHaveCount($shouldWake ? 1 : 0);
})->with([
    'database batch wakes worker' => ['database', true],
    'sync batch does not wake worker' => ['sync', false],
]);

it('registers Queue.Wake on both platforms', function (string $registrationPath, string $functionPath) {
    expect(queueWorkerResource($registrationPath))->toContain('"Queue.Wake"')
        ->and(queueWorkerResource($functionPath))->toContain('class Wake');
})->with([
    'iOS' => [
        'xcode/NativePHP/Bridge/BridgeFunctionRegistration.swift',
        'xcode/NativePHP/Bridge/Functions/QueueFunctions.swift',
    ],
    'Android' => [
        'androidstudio/app/src/main/java/com/nativephp/mobile/bridge/BridgeFunctionRegistration.kt',
        'androidstudio/app/src/main/java/com/nativephp/mobile/bridge/functions/QueueFunctions.kt',
    ],
]);

it('reads queue_worker_mode, probes jobs read-only, and pauses in background on both platforms', function () {
    $iosApp = queueWorkerResource('xcode/NativePHP/NativePHPApp.swift');
    $iosWorker = queueWorkerResource('xcode/NativePHP/Bridge/PHPQueueWorker.swift');
    $iosLifecycle = queueWorkerResource('xcode/NativePHP/AppLifecycleMonitor.swift');
    $iosC = queueWorkerResource('xcode/Include/Bridge/PHP.c');

    expect($iosApp)
        ->toContain('static func getQueueWorkerMode()')
        ->toContain('PHPQueueWorker.shared.startIfNeeded()')
        ->and($iosWorker)
        ->toContain('func startIfNeeded()')
        ->toContain('func wake() -> Bool')
        ->toContain('func pause()')
        ->toContain('func resume()')
        ->toContain('_worker_php_has_pending_jobs')
        ->and($iosLifecycle)
        ->toContain('PHPQueueWorker.shared.pause()')
        ->toContain('PHPQueueWorker.shared.resume()')
        ->and($iosC)
        ->toContain('worker_php_has_pending_jobs')
        ->toContain('SQLITE_OPEN_READONLY')
        ->toContain('SELECT 1 FROM jobs LIMIT 1');

    $androidEnv = queueWorkerResource('androidstudio/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt');
    $androidWorker = queueWorkerResource('androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPQueueWorker.kt');
    $androidActivity = queueWorkerResource('androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt');

    expect($androidEnv)
        ->toContain('fun getQueueWorkerMode(')
        ->toContain('fun hasPendingQueueJobs(')
        ->toContain('OPEN_READONLY')
        ->toContain('SELECT 1 FROM jobs LIMIT 1')
        ->and($androidWorker)
        ->toContain('fun pause()')
        ->toContain('fun resume()')
        ->toContain('fun wake()')
        ->toContain('fun wakeShared()')
        ->toContain('fun clearWakeHandler(')
        ->and($androidActivity)
        ->toContain('LaravelEnvironment.getQueueWorkerMode(this)')
        ->toContain('queueWorker?.pause()')
        ->toContain('queueWorker?.resume()')
        ->toContain('PHPQueueWorker.setWakeHandler(queueWakeHandler)')
        ->toContain('PHPQueueWorker.clearWakeHandler(queueWakeHandler)');
});
