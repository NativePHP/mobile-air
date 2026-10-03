<?php

use Native\Mobile\Events\Biometric\Completed;
use Native\Mobile\PendingBiometric;
use Native\Mobile\Testing\FakeBridge;

/**
 * prompt() reports whether the native prompt started. The bridge returns a
 * function's data unwrapped on success and {"status": "error", ...} on
 * failure, so only an error (or no answer at all) means it did not start.
 */
beforeEach(fn () => FakeBridge::enable());
afterEach(fn () => FakeBridge::disable());

it('sends the id and event class to Biometric.Prompt', function () {
    (new PendingBiometric)->id('unlock-1')->prompt();

    FakeBridge::current()->assertCalled('Biometric.Prompt', fn ($params) => $params === [
        'id' => 'unlock-1',
        'event' => Completed::class,
    ]);
});

it('reports a started prompt for the payloads mobile-biometrics returns', function (string $response) {
    FakeBridge::current()->respondTo('Biometric.Prompt', $response);

    expect((new PendingBiometric)->prompt())->toBeTrue();
})->with([
    'android' => '{"launched":true}',
    'ios' => '{}',
    'empty array' => '[]',
]);

it('reports a bridge error as not started', function () {
    FakeBridge::current()->respondTo('Biometric.Prompt', [
        'status' => 'error',
        'code' => 'UNKNOWN_ERROR',
        'message' => 'Unexpected error',
        'data' => [],
    ]);

    expect((new PendingBiometric)->prompt())->toBeFalse();
});

it('reports a missing bridge function as not started', function () {
    FakeBridge::current()->respondTo('Biometric.Prompt', null);

    expect((new PendingBiometric)->prompt())->toBeFalse();
});

it('only prompts once', function () {
    FakeBridge::current()->respondTo('Biometric.Prompt', '{}');

    $pending = new PendingBiometric;

    expect($pending->prompt())->toBeTrue()
        ->and($pending->prompt())->toBeFalse();

    FakeBridge::current()->assertCalledTimes('Biometric.Prompt', 1);
});
