<?php

/**
 * The transport spools what core's AsyncTaskSeal has sealed, and desktop
 * uses that seal as well. These check that it refuses, from this end,
 * text with no purpose in front and work under a previous app key.
 */

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\FakeBridge;

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

/** Where the spool keeps the payload of a task id. */
function sealProbePath(string $id): string
{
    return AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.$id.'.task';
}

beforeEach(function () {
    sealProbeUseKeys(sealProbeKey());
});

afterEach(function () {
    FakeBridge::disable();

    foreach (['payload', 'complete'] as $sub) {
        array_map('unlink', glob(AsyncTaskTransport::directory($sub).DIRECTORY_SEPARATOR.'*') ?: []);
    }
});

it('does not open what the app key sealed with a task id in front but no purpose', function () {
    $id = (string) Str::uuid();

    File::ensureDirectoryExists(AsyncTaskTransport::directory('payload'), 0700);
    file_put_contents(sealProbePath($id), app('encrypter')->encryptString($id."\n".serialize(['kind' => 'closure'])));

    expect(AsyncTaskTransport::readPayload($id))->toBeNull();
});

it('does not open a payload sealed under a key the app now has as a previous key', function () {
    $previous = sealProbeKey();
    $id = (string) Str::uuid();
    $payload = serialize(['kind' => 'closure']);

    sealProbeUseKeys($previous);
    FakeBridge::enable()->respondTo('AsyncTask.Dispatch', ['success' => true]);

    expect(AsyncTaskTransport::dispatch($id, $payload))->toBeTrue();

    sealProbeUseKeys(sealProbeKey(), [$previous]);

    // The app's own encrypter still decrypts it with the previous key.
    expect(app('encrypter')->decryptString(file_get_contents(sealProbePath($id))))->toEndWith($payload)
        ->and(AsyncTaskTransport::readPayload($id))->toBeNull();
});
