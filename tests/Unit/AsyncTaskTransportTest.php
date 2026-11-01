<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Exceptions\AsyncTaskException;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\FakeBridge;
use Symfony\Component\Process\Process;

/**
 * What anything able to write to the spool could leave there: an object whose
 * magic methods run the moment unserialize() reads it.
 */
class PlantedTaskGadget
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        static::$woken = true;
    }
}

beforeEach(function () {
    // Payloads are sealed with the app key, which every app on a device has.
    config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'))]);

    PlantedTaskGadget::$woken = false;
});

/** Where the spool keeps the payload of a task id. */
function payloadPath(string $id): string
{
    return AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.$id.'.task';
}

/** Seal a payload for a task id and spool it, as a dispatch does. */
function spoolPayload(string $id, string $payload): void
{
    $writer = (new ReflectionClass(AsyncTaskTransport::class))->getMethod('writePayload');
    $writer->setAccessible(true);
    $writer->invoke(null, $id, $payload);
}

/** Put bytes in the spool for a task id without sealing them. */
function plantPayload(string $id, string $contents): void
{
    File::ensureDirectoryExists(AsyncTaskTransport::directory('payload'));
    file_put_contents(payloadPath($id), $contents);
}

afterEach(function () {
    FakeBridge::disable();

    // Clean the spool dirs between tests.
    foreach (['payload', 'complete'] as $sub) {
        $dir = AsyncTaskTransport::directory($sub);
        if (is_dir($dir)) {
            array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
        }
    }
});

it('round-trips a payload through the temp spool and consumes it on read', function () {
    $id = (string) Str::uuid();
    $payload = serialize(['kind' => 'closure', 'secret' => 'captured-value']);

    spoolPayload($id, $payload);

    // The file on disk is encrypted, so what the closure captured isn't readable there.
    expect(file_get_contents(payloadPath($id)))->not->toContain('captured-value');

    expect(AsyncTaskTransport::readPayload($id))->toBe($payload)
        // Second read is null — readPayload deletes the file (consume-once).
        ->and(AsyncTaskTransport::readPayload($id))->toBeNull();
});

it('runs no task file that this app did not seal', function () {
    $bridge = FakeBridge::enable();
    $id = (string) Str::uuid();

    plantPayload($id, serialize(new PlantedTaskGadget));

    Artisan::call('native:async:run', ['--id' => $id]);

    $completion = $bridge->callsTo('AsyncTask.Complete')[0]['params'];

    // Never unserialized, and the run still reports an outcome for the id.
    expect(PlantedTaskGadget::$woken)->toBeFalse()
        ->and($completion['event'])->toBe(AsyncTaskFailed::class)
        ->and($completion['payload']['message'])->toContain('not sealed by this app')
        ->and(is_file(payloadPath($id)))->toBeFalse();
});

it('does not open a sealed payload under another task id', function () {
    $sealedFor = (string) Str::uuid();
    $movedTo = (string) Str::uuid();

    spoolPayload($sealedFor, serialize(['kind' => 'closure']));
    rename(payloadPath($sealedFor), payloadPath($movedTo));

    expect(AsyncTaskTransport::readPayload($movedTo))->toBeNull();
});

it('does not open something else the app key encrypted as a task', function () {
    // The app key also encrypts cookies and `encrypted` casts, which can hold
    // text a user typed. None of that may open as a task payload.
    $id = (string) Str::uuid();

    plantPayload($id, Crypt::encryptString(serialize(new PlantedTaskGadget)));

    expect(AsyncTaskTransport::readPayload($id))->toBeNull()
        ->and(PlantedTaskGadget::$woken)->toBeFalse();
});

it('looks up no file for an id that is not a task id', function () {
    // Native takes the id from any bridge call, a web view page's included,
    // and the id becomes part of a path.
    File::ensureDirectoryExists(AsyncTaskTransport::directory());
    $outside = AsyncTaskTransport::directory('outside.task');
    file_put_contents($outside, 'not a task');

    expect(AsyncTaskTransport::readPayload('../outside'))->toBeNull()
        ->and(is_file($outside))->toBeTrue();

    unlink($outside);
});

it('fails the dispatch when the app has no key to seal the task with', function () {
    config(['app.key' => null]);
    app()->forgetInstance('encrypter');

    $bridge = FakeBridge::enable()->respondTo('AsyncTask.Dispatch', ['success' => true]);
    $error = null;

    AsyncTask::dispatch(static fn () => 'never runs')
        ->failed(function (AsyncTaskException $e) use (&$error) {
            $error = $e;
        })
        ->start();

    expect($error)->toBeInstanceOf(AsyncTaskException::class)
        ->and($error->getMessage())->toContain('need an app key')
        ->and($bridge->callsTo('AsyncTask.Dispatch'))->toBe([])
        ->and(glob(AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.'*') ?: [])->toBe([]);
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
    // Drive the refusal through the bridge rather than skipping when one
    // exists: the Jump polyfill defines nativephp_call() in every testbench
    // boot, so a function_exists() guard here would never run at all.
    $bridge = FakeBridge::enable();
    $bridge->respondTo('AsyncTask.Dispatch', ['success' => false, 'error' => 'executor not running']);

    $id = 'refused-'.uniqid();

    expect(AsyncTaskTransport::dispatch($id, serialize(['kind' => 'closure'])))->toBeFalse()
        ->and(is_file(AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.$id.'.task'))->toBeFalse();

    FakeBridge::disable();
});

it('accepts the task when the lane reports success', function () {
    $bridge = FakeBridge::enable();
    $bridge->respondTo('AsyncTask.Dispatch', ['success' => true]);

    $id = 'accepted-'.uniqid();

    expect(AsyncTaskTransport::dispatch($id, serialize(['kind' => 'closure'])))->toBeTrue()
        // Payload stays on disk for the background runner to consume.
        ->and(is_file(AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.$id.'.task'))->toBeTrue();

    FakeBridge::disable();
});

it('treats an unanswered dispatch as a refusal', function () {
    // No scripted response → the polyfill returns null. A bridge that says
    // nothing hasn't accepted anything.
    FakeBridge::enable();

    $id = 'silent-'.uniqid();

    expect(AsyncTaskTransport::dispatch($id, serialize(['kind' => 'closure'])))->toBeFalse();

    FakeBridge::disable();
});

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

it('fails a jump runner that exits without spooling a completion', function () {
    // The dev-loop failure: a parse error from a half-saved file, a fatal in
    // bootstrap, an OOM, a SIGKILL. The process is gone and nothing was
    // spooled, so dropping its entry would drop the deadline with it and
    // nothing would ever report on the task.
    $id = 'died-'.uniqid();

    $process = new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "Parse error: syntax error"); exit(255);']);
    $process->run();

    $entries = new ReflectionProperty(AsyncTaskTransport::class, 'jumpProcesses');
    $entries->setAccessible(true);
    $entries->setValue(null, [$id => [
        'process' => $process,
        'deadline' => microtime(true) + 60,
        'event' => AsyncTaskFailed::class,
        'payload' => ['id' => $id],
    ]]);

    $event = AsyncTaskTransport::drainJumpCompletion();

    expect($event)->toBeArray()
        ->and($event['event'])->toBe(AsyncTaskFailed::class)
        ->and($event['payload']['id'])->toBe($id)
        ->and($event['payload']['message'])->toContain('exit code 255')
        // The runner's own words, so the cause is visible rather than a
        // generic timeout a minute later.
        ->and($event['payload']['message'])->toContain('Parse error')
        // Entry cleared, so the failure is delivered exactly once.
        ->and($entries->getValue())->toBe([]);
});

it('leaves a jump runner that exited cleanly alone', function () {
    $id = 'clean-'.uniqid();

    $dir = AsyncTaskTransport::directory('complete');
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.DIRECTORY_SEPARATOR.$id.'.json', json_encode([
        'event' => AsyncTaskFinished::class,
        'payload' => ['id' => $id, 'result' => 'DONE'],
    ]));

    $process = new Process([PHP_BINARY, '-r', 'exit(0);']);
    $process->run();

    $entries = new ReflectionProperty(AsyncTaskTransport::class, 'jumpProcesses');
    $entries->setAccessible(true);
    $entries->setValue(null, [$id => [
        'process' => $process,
        'deadline' => microtime(true) + 60,
        'event' => AsyncTaskFailed::class,
        'payload' => ['id' => $id],
    ]]);

    // Its real outcome is already spooled; no synthesized failure on top of it.
    $event = AsyncTaskTransport::drainJumpCompletion();

    expect($event['event'])->toBe(AsyncTaskFinished::class)
        ->and($event['payload']['result'])->toBe('DONE')
        ->and(AsyncTaskTransport::drainJumpCompletion())->toBeNull();
});
