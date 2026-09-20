<?php

use Native\Mobile\SecureStorage;
use Native\Mobile\SecureStorageAccessibility;
use Native\Mobile\SecureStorageResult;
use Native\Mobile\SecureStorageStatus;
use Native\Mobile\Testing\FakeBridge;

afterEach(fn () => FakeBridge::disable());

// ── Reads: the four outcomes ────────────────────────

it('reports a hit with its value', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', [
        'status' => 'found',
        'value' => 'token-abc',
    ]);

    $result = (new SecureStorage)->read('access_token');

    expect($result->status)->toBe(SecureStorageStatus::Found)
        ->and($result->found())->toBeTrue()
        ->and($result->value)->toBe('token-abc');
});

it('reports a key that holds nothing', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', [
        'status' => 'not_found',
        'value' => null,
    ]);

    $result = (new SecureStorage)->read('access_token');

    expect($result->missing())->toBeTrue()
        ->and($result->found())->toBeFalse()
        ->and($result->value)->toBeNull();
});

it('reports a locked device apart from a missing key', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', [
        'status' => 'unavailable',
        'value' => null,
        'code' => 'INTERACTION_NOT_ALLOWED',
        'message' => 'Protected data is unavailable while the device is locked',
    ]);

    $result = (new SecureStorage)->read('access_token');

    expect($result->unavailable())->toBeTrue()
        ->and($result->missing())->toBeFalse()
        ->and($result->code)->toBe('INTERACTION_NOT_ALLOWED');
});

it('reports a native failure with its code and message', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', [
        'status' => 'error',
        'code' => 'FUNCTION_NOT_FOUND',
        'message' => "Function 'SecureStorage.Get' not found in bridge registry",
    ]);

    $result = (new SecureStorage)->read('access_token');

    expect($result->failed())->toBeTrue()
        ->and($result->missing())->toBeFalse()
        ->and($result->code)->toBe('FUNCTION_NOT_FOUND');
});

it('treats a locked-device error envelope as unavailable', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', [
        'status' => 'error',
        'code' => 'PROTECTED_DATA_UNAVAILABLE',
        'message' => 'Protected data is unavailable',
    ]);

    expect((new SecureStorage)->read('access_token')->unavailable())->toBeTrue();
});

it('fails a read when the bridge answers nothing', function () {
    FakeBridge::enable();

    $result = (new SecureStorage)->read('access_token');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe('BRIDGE_UNAVAILABLE');
});

it('fails a read when the bridge answers something undecodable', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', 'not json at all');

    $result = (new SecureStorage)->read('access_token');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe('MALFORMED_RESPONSE');
});

// ── Reads: plugin builds without the status vocabulary ──

it('infers a hit from a bare value', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', ['value' => 'token-abc']);

    expect((new SecureStorage)->read('access_token')->found())->toBeTrue();
});

it('infers a miss from the empty string an older plugin sends', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', ['value' => '']);

    $result = (new SecureStorage)->read('access_token');

    expect($result->missing())->toBeTrue()
        ->and($result->value)->toBeNull();
});

it('keeps a locked read distinguishable on an older plugin', function () {
    // 1.0.1 raises the Keychain's errSecInteractionNotAllowed through the
    // generic bridge error envelope — Failed, but still not NotFound.
    FakeBridge::enable()->respondTo('SecureStorage.Get', [
        'status' => 'error',
        'code' => 'EXECUTION_FAILED',
        'message' => 'Function execution failed: Failed to load from keychain (status: -25308)',
    ]);

    $result = (new SecureStorage)->read('access_token');

    expect($result->failed())->toBeTrue()
        ->and($result->missing())->toBeFalse();
});

// ── get() stays a plain ?string ─────────────────────

it('still returns the value from get', function () {
    FakeBridge::enable()->respondTo('SecureStorage.Get', [
        'status' => 'found',
        'value' => 'token-abc',
    ]);

    expect((new SecureStorage)->get('access_token'))->toBe('token-abc');
});

it('still returns null from get for every non-hit', function (array $response) {
    FakeBridge::enable()->respondTo('SecureStorage.Get', $response);

    expect((new SecureStorage)->get('access_token'))->toBeNull();
})->with([
    'not found' => [['status' => 'not_found', 'value' => null]],
    'unavailable' => [['status' => 'unavailable', 'value' => null]],
    'failed' => [['status' => 'error', 'code' => 'EXECUTION_FAILED']],
    'legacy empty string' => [['value' => '']],
]);

// ── Writes ──────────────────────────────────────────

it('sends no accessibility when none is asked for', function () {
    $bridge = FakeBridge::enable()->respondTo('SecureStorage.Set', ['success' => true]);

    expect((new SecureStorage)->set('access_token', 'token-abc'))->toBeTrue();

    $bridge->assertCalled('SecureStorage.Set', fn (array $params) => $params === [
        'key' => 'access_token',
        'value' => 'token-abc',
    ]);
});

it('sends the accessibility a key is stored with', function () {
    $bridge = FakeBridge::enable()->respondTo('SecureStorage.Set', ['success' => true]);

    (new SecureStorage)->set('access_token', 'token-abc', SecureStorageAccessibility::AfterFirstUnlock);

    $bridge->assertCalled(
        'SecureStorage.Set',
        fn (array $params) => $params['accessibility'] === 'after_first_unlock'
    );
});

it('still deletes a key by setting null', function () {
    $bridge = FakeBridge::enable()->respondTo('SecureStorage.Set', ['success' => true]);

    (new SecureStorage)->set('access_token', null);

    $bridge->assertCalled(
        'SecureStorage.Set',
        fn (array $params) => array_key_exists('value', $params) && $params['value'] === null
    );
});

// ── Result parsing in isolation ─────────────────────

it('falls back to a default for anything but a hit', function () {
    expect(SecureStorageResult::fromBridge('{"status":"found","value":"v"}')->valueOr('fallback'))->toBe('v')
        ->and(SecureStorageResult::fromBridge('{"status":"unavailable"}')->valueOr('fallback'))->toBe('fallback')
        ->and(SecureStorageResult::fromBridge(null)->valueOr('fallback'))->toBe('fallback');
});

it('keeps an explicitly stored empty string', function () {
    $result = SecureStorageResult::fromBridge('{"status":"found","value":""}');

    expect($result->found())->toBeTrue()
        ->and($result->value)->toBe('');
});
