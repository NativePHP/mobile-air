<?php

use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\StreamDeltaPayload;
use Tests\Fixtures\Edge\TypedPayloadScreen;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('builds a NativeEventPayload parameter from the whole payload', function () {
    $screen = Native::test(TypedPayloadScreen::class)
        ->emitNative('stream:delta', ['request_id' => 'req-1', 'text' => 'Hello']);

    expect($screen->instance()->delta)->toEqual(new StreamDeltaPayload('req-1', 'Hello'));
});

it('binds other parameters by name next to a typed payload', function () {
    $screen = Native::test(TypedPayloadScreen::class)
        ->emitNative('stream:labelled', ['request_id' => 'req-2', 'text' => 'Hi', 'label' => 'greeting']);

    expect($screen->instance()->delta)->toEqual(new StreamDeltaPayload('req-2', 'Hi'))
        ->and($screen->instance()->label)->toBe('greeting');
});

it('still injects class-typed parameters that are not payloads from the container', function () {
    $screen = Native::test(TypedPayloadScreen::class)
        ->emitNative('stream:injected', ['anything' => true]);

    expect($screen->instance()->injected)->toBeTrue();
});

it('surfaces a payload the event class rejects instead of skipping the listener', function () {
    Native::test(TypedPayloadScreen::class)
        ->emitNative('stream:delta', ['text' => 'no request id']);
})->throws(InvalidArgumentException::class, 'A stream delta needs a request_id.');
