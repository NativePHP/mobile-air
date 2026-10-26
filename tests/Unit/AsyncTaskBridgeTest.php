<?php

/**
 * JavaScript in a web view can call any registered bridge function through
 * /_native/api/call, the two of the async task lane among them. So native
 * trusts neither call: Dispatch takes only a UUID for an id, which the C lane
 * splices into PHP that it evaluates, and posts a timeout under one fixed
 * name. Complete answers only a call made on a thread of the async lane.
 *
 * Those checks live in Swift, Kotlin and C, which no test here can run. These
 * read the sources instead, to keep the twins in step with each other and
 * with the PHP side, and run what PHP does when native refuses a completion.
 */

use Illuminate\Support\Facades\Log;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\FakeBridge;

function asyncBridgeSource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/'.$path);
}

/** The body of the C function whose source holds $needle, braces included. */
function asyncBridgeFunctionAround(string $source, string $needle): string
{
    $at = strpos($source, $needle);
    expect($at)->not->toBeFalse("{$needle} not found");

    // The opening brace of a C function sits at the start of a line after
    // its signature, or ends that line; the last one before the needle
    // that starts a line with no indent is the function's own.
    preg_match_all('/^[A-Za-z].*\)\s*\{\s*$/m', substr($source, 0, $at), $signatures, PREG_OFFSET_CAPTURE);
    $start = end($signatures[0])[1];

    $open = strpos($source, '{', $start);
    $depth = 0;

    for ($i = $open, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}' && --$depth === 0) {
            return substr($source, $start, $i - $start + 1);
        }
    }

    throw new RuntimeException("Unbalanced braces around {$needle}");
}

afterEach(function () {
    FakeBridge::disable();
});

it('posts a timeout under the name of AsyncTaskFailed on both platforms', function (string $path, string $pattern) {
    preg_match($pattern, asyncBridgeSource($path), $match);

    expect($match)->not->toBeEmpty("No timeout event constant in {$path}")
        ->and(stripcslashes($match[1]))->toBe(AsyncTaskFailed::class);
})->with([
    'iOS' => ['xcode/NativePHP/Bridge/Functions/AsyncFunctions.swift', '/static let timeoutEvent = "([^"]+)"/'],
    'Android' => ['androidstudio/app/src/main/java/com/nativephp/mobile/bridge/functions/AsyncFunctions.kt', '/const val TIMEOUT_EVENT = "([^"]+)"/'],
]);

it('checks the id is a UUID before the async lane splices it into PHP', function (string $path) {
    $body = asyncBridgeFunctionAround(asyncBridgeSource($path), 'native:async:run --id=%s');

    $check = strpos($body, 'if (!async_task_id_is_uuid(');
    $splice = strpos($body, 'snprintf(eval_code');

    expect($check)->not->toBeFalse('The splice is not guarded by async_task_id_is_uuid()')
        ->and($check)->toBeLessThan($splice);
})->with([
    'iOS' => 'xcode/Include/Bridge/PHP.c',
    'Android' => 'androidstudio/app/src/main/cpp/php_bridge.c',
]);

it('refuses an id that is not a UUID in Dispatch on both platforms', function (string $path, string $check) {
    $source = asyncBridgeSource($path);
    $dispatch = substr($source, strpos($source, 'class Dispatch'), strpos($source, 'class Complete') - strpos($source, 'class Dispatch'));

    expect($dispatch)->toContain($check)
        // The executor is never reached before the id has been checked.
        ->and(strpos($dispatch, $check))->toBeLessThan(strpos($dispatch, 'AsyncTaskExecutor'));
})->with([
    'iOS' => ['xcode/NativePHP/Bridge/Functions/AsyncFunctions.swift', 'guard AsyncFunctions.isTaskId(id) else'],
    'Android' => ['androidstudio/app/src/main/java/com/nativephp/mobile/bridge/functions/AsyncFunctions.kt', 'if (!isTaskId(id))'],
]);

it('answers Complete only on a thread of the async lane on both platforms', function (string $path, string $check) {
    $source = asyncBridgeSource($path);
    $complete = substr($source, strpos($source, 'class Complete'));

    expect(strpos($complete, $check))->not->toBeFalse()
        ->and(strpos($complete, $check))->toBeLessThan(strpos($complete, 'sendNativeEvent'));
})->with([
    'iOS' => ['xcode/NativePHP/Bridge/Functions/AsyncFunctions.swift', 'guard AsyncTaskExecutor.onLaneThread else'],
    'Android' => ['androidstudio/app/src/main/java/com/nativephp/mobile/bridge/functions/AsyncFunctions.kt', 'if (!AsyncTaskExecutor.onLaneThread)'],
]);

it('marks the threads of the async lane and no other', function () {
    $ios = asyncBridgeSource('xcode/Include/Bridge/PHP.c');
    $android = asyncBridgeSource('androidstudio/app/src/main/java/com/nativephp/mobile/bridge/AsyncTaskExecutor.kt');

    $workerLoop = substr($android, strpos($android, 'private fun workerLoop'));

    expect(asyncBridgeFunctionAround($ios, 'ASYNC-PHP: thread started'))->toContain('async_lane_thread = 1;')
        ->and(substr_count($ios, 'async_lane_thread = 1;'))->toBe(1)
        ->and(substr_count($android, 'laneThread.set(true)'))->toBe(1)
        ->and(strpos($workerLoop, 'laneThread.set(true)'))->toBeLessThan(strpos($workerLoop, 'phpBridge.runAsyncTask'));
});

it('logs a warning when native refuses the outcome of a task', function () {
    Log::spy();

    FakeBridge::enable()->respondTo('AsyncTask.Complete', ['success' => false, 'error' => 'not called from the async lane']);

    AsyncTaskTransport::complete('task-1', AsyncTaskFinished::class, ['id' => 'task-1', 'result' => 'done']);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message) => str_contains($message, '[task-1]') && str_contains($message, 'not called from the async lane'),
    );
});

it('logs nothing when native takes the outcome of a task', function () {
    Log::spy();

    FakeBridge::enable()->respondTo('AsyncTask.Complete', ['success' => true]);

    AsyncTaskTransport::complete('task-2', AsyncTaskFailed::class, ['id' => 'task-2', 'message' => 'boom']);

    Log::shouldNotHaveReceived('warning');
});
