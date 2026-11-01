<?php

/**
 * The transport spools what core's AsyncTaskSeal has sealed, and desktop
 * uses that seal as well. These try two refusals of it from this end:
 * text with no purpose in front, and one of the previous app keys.
 */

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\FakeBridge;

/** What a planted payload could hold: an object that notes it was built. */
class SealProbeGadget
{
    public static bool $built = false;

    public function __unserialize(array $data): void
    {
        static::$built = true;
    }
}

/** A key for the app, as `config('app.key')` holds one. */
function sealProbeKey(): string
{
    return 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
}

/** Give the app this key, and these previous keys, from the next use of its encrypter on. */
function sealProbeUseKeys(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);

    app()->forgetInstance('encrypter');
}

function sealProbePath(string $id): string
{
    return AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.$id.'.task';
}

beforeEach(function () {
    sealProbeUseKeys(sealProbeKey());

    SealProbeGadget::$built = false;
});

afterEach(function () {
    FakeBridge::disable();

    foreach (['payload', 'complete'] as $sub) {
        array_map('unlink', glob(AsyncTaskTransport::directory($sub).DIRECTORY_SEPARATOR.'*') ?: []);
    }
});

it('does not open what the app key sealed with a task id in front but no purpose', function () {
    $bridge = FakeBridge::enable();
    $id = (string) Str::uuid();

    @mkdir(AsyncTaskTransport::directory('payload'), 0700, true);
    file_put_contents(sealProbePath($id), app('encrypter')->encryptString($id."\n".serialize(new SealProbeGadget)));

    Artisan::call('native:async:run', ['--id' => $id]);

    $completion = $bridge->callsTo('AsyncTask.Complete')[0]['params'];

    expect(SealProbeGadget::$built)->toBeFalse()
        ->and($completion['event'])->toBe(AsyncTaskFailed::class)
        ->and($completion['payload']['message'])->toContain('not sealed by this app');
});

it('does not open a payload sealed under a key the app now has as a previous key', function () {
    $previous = sealProbeKey();
    $id = (string) Str::uuid();
    $payload = serialize(['kind' => 'closure']);

    sealProbeUseKeys($previous);
    FakeBridge::enable()->respondTo('AsyncTask.Dispatch', ['success' => true]);

    expect(AsyncTaskTransport::dispatch($id, $payload))->toBeTrue();

    sealProbeUseKeys(sealProbeKey(), [$previous]);
    Log::spy();

    // The app's own encrypter still decrypts it with the previous key.
    expect(app('encrypter')->decryptString(file_get_contents(sealProbePath($id))))->toEndWith($payload)
        ->and(AsyncTaskTransport::readPayload($id))->toBeNull()
        ->and(is_file(sealProbePath($id)))->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, "[{$id}] was not sealed by this app"))->once();
});
