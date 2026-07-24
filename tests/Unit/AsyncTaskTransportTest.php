<?php

use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Support\AsyncTaskTransport;

afterEach(function () {
    // Clean the spool dirs between tests.
    foreach (['payload', 'complete'] as $sub) {
        $dir = AsyncTaskTransport::directory($sub);
        if (is_dir($dir)) {
            array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
        }
    }
});

it('round-trips a payload through the temp spool and consumes it on read', function () {
    $id = 'task-'.uniqid();
    $payload = serialize(['kind' => 'closure']);

    // writePayload is protected; go through the public dispatch path but stub the
    // trigger by faking Jump off — instead call the reflected writer directly.
    $writer = (new ReflectionClass(AsyncTaskTransport::class))->getMethod('writePayload');
    $writer->setAccessible(true);
    $writer->invoke(null, $id, $payload);

    expect(AsyncTaskTransport::readPayload($id))->toBe($payload)
        // Second read is null — readPayload deletes the file (consume-once).
        ->and(AsyncTaskTransport::readPayload($id))->toBeNull();
});

it('drains the oldest jump completion as a native event and removes it', function () {
    $dir = AsyncTaskTransport::directory('complete');
    @mkdir($dir, 0755, true);

    file_put_contents($dir.'/aaa.json', json_encode([
        'event' => AsyncTaskFinished::class,
        'payload' => ['id' => 'aaa', 'result' => 'first'],
    ]));

    $event = AsyncTaskTransport::drainJumpCompletion();

    expect($event)->toBeArray()
        ->and($event['type'])->toBe(20)
        ->and($event['event'])->toBe(AsyncTaskFinished::class)
        ->and($event['payload']['result'])->toBe('first')
        // File consumed.
        ->and(AsyncTaskTransport::drainJumpCompletion())->toBeNull();
});

it('returns null when there is no completion to drain', function () {
    expect(AsyncTaskTransport::drainJumpCompletion())->toBeNull();
});

it('stores and forgets scope metadata', function () {
    AsyncTaskRegistry::register('t1', 12345, null);
    AsyncTaskRegistry::register('t2', null, 'my-alias');

    expect(AsyncTaskRegistry::scope('t1'))->toBe(['origin' => 12345, 'shared' => null])
        ->and(AsyncTaskRegistry::scope('t2'))->toBe(['origin' => null, 'shared' => 'my-alias'])
        ->and(AsyncTaskRegistry::scope('missing'))->toBeNull();

    AsyncTaskRegistry::forget('t1');
    expect(AsyncTaskRegistry::scope('t1'))->toBeNull();
});
