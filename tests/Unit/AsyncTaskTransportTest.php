<?php

use Illuminate\Support\Facades\File;
use Native\Mobile\Events\Async\AsyncTaskFailed;
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
    $origin = new stdClass;

    AsyncTaskRegistry::register('t1', $origin, null);
    AsyncTaskRegistry::register('t2', null, 'my-alias');

    expect(AsyncTaskRegistry::origin('t1'))->toBe($origin)
        ->and(AsyncTaskRegistry::scope('t1')['shared'])->toBeNull()
        ->and(AsyncTaskRegistry::scope('t2'))->toBe(['origin' => null, 'shared' => 'my-alias'])
        ->and(AsyncTaskRegistry::scope('missing'))->toBeNull();

    AsyncTaskRegistry::forget('t1');
    expect(AsyncTaskRegistry::scope('t1'))->toBeNull();
});

it('holds the origin screen weakly so a recycled object id cannot be mistaken for it', function () {
    $origin = new stdClass;
    AsyncTaskRegistry::register('t3', $origin, null);

    // The screen is popped and freed while the task is still running.
    unset($origin);

    expect(AsyncTaskRegistry::origin('t3'))->toBeNull()
        // The scope itself is still there — the task is in flight, it just has
        // no live component to deliver to.
        ->and(AsyncTaskRegistry::scope('t3'))->toBeArray();
});

it('drops the spooled payload when the background lane refuses the task', function () {
    $id = 'refused-'.uniqid();

    // No Jump, no nativephp_call registered in-process → nothing accepts it.
    expect(AsyncTaskTransport::dispatch($id, serialize(['kind' => 'closure'])))->toBeFalse()
        ->and(is_file(AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.$id.'.task'))->toBeFalse();
})->skip(fn () => function_exists('nativephp_call'), 'A bridge is available in this process.');

it('falls back to a failure envelope when a completion will not encode', function () {
    $encode = (new ReflectionClass(AsyncTaskTransport::class))->getMethod('encodeCompletion');
    $encode->setAccessible(true);

    // An invalid UTF-8 byte sequence — json_encode() returns false on this.
    $json = $encode->invoke(null, [
        'id' => 'x1',
        'event' => AsyncTaskFinished::class,
        'payload' => ['id' => 'x1', 'result' => "\xB1\x31"],
    ], 'x1');

    $decoded = json_decode($json, true);

    expect($decoded['event'])->toBe(AsyncTaskFailed::class)
        ->and($decoded['payload']['id'])->toBe('x1')
        ->and($decoded['payload']['message'])->toContain('could not be encoded');
});

it('drains jump completions in the order they finished, not by filename', function () {
    // Spool names are random UUIDs, so a lexicographic sort orders them
    // arbitrarily. Stage two completions where name order is the REVERSE of
    // completion order and assert the older one drains first.
    $dir = AsyncTaskTransport::directory('complete');
    File::ensureDirectoryExists($dir);

    $older = $dir.'/zzzzzzzz-0000-0000-0000-000000000000.json';
    $newer = $dir.'/aaaaaaaa-0000-0000-0000-000000000000.json';

    file_put_contents($older, json_encode(['event' => 'First', 'payload' => ['id' => 'older']]));
    file_put_contents($newer, json_encode(['event' => 'Second', 'payload' => ['id' => 'newer']]));

    touch($older, time() - 60);
    touch($newer, time());
    clearstatcache();

    // Name order would give 'aaaa…' (the NEWER one) first.
    expect(AsyncTaskTransport::drainJumpCompletion()['payload']['id'])->toBe('older')
        ->and(AsyncTaskTransport::drainJumpCompletion()['payload']['id'])->toBe('newer');
});

it('keeps the spool owner-only', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('POSIX permissions are not meaningful on Windows.');
    }

    // The payload is handed straight to unserialize() by the runner, and a task
    // subclass's constructor args are not signed the way a closure is.
    $dir = AsyncTaskTransport::directory('payload');
    File::deleteDirectory(AsyncTaskTransport::directory());
    AsyncTaskTransport::dispatch('perm-check', serialize(['kind' => 'closure']));

    clearstatcache();
    expect(fileperms($dir) & 0777)->toBe(0700);

    // ...and a directory left world-readable by an earlier run is tightened.
    chmod($dir, 0755);
    AsyncTaskTransport::dispatch('perm-check-2', serialize(['kind' => 'closure']));
    clearstatcache();
    expect(fileperms($dir) & 0777)->toBe(0700);
});

it('reports pending jump runners so the runloop does not block past a completion', function () {
    // Nothing WAKES a blocked nativephp_element_wait_event() when a completion
    // spools under Jump — a screen with no #[Poll] asks for timeout -1, so the
    // completion would sit undelivered until an unrelated tap woke the loop.
    // The polyfill clamps its wait while this returns true.
    expect(AsyncTaskTransport::hasPendingJumpRunners())->toBeFalse();

    // A spooled-but-undrained completion must keep the loop ticking even when
    // the subprocess itself has already exited.
    $dir = AsyncTaskTransport::directory('complete');
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/pending-probe.json', json_encode([
        'event' => AsyncTaskFinished::class,
        'payload' => ['id' => 'pending-probe'],
    ]));

    expect(AsyncTaskTransport::hasPendingJumpRunners())->toBeTrue();

    AsyncTaskTransport::drainJumpCompletion();

    expect(AsyncTaskTransport::hasPendingJumpRunners())->toBeFalse();
});
