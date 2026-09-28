<?php

use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\PayloadListenerScreen;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('passes the whole payload to a single array parameter that names no payload key', function () {
    Native::test(PayloadListenerScreen::class)
        ->emitNative('WholePayloadReceived', ['message' => 'hi', 'id' => 7])
        ->assertSet('wholePayload', ['message' => 'hi', 'id' => 7]);
});

it('keeps binding a single array parameter by name when the payload has that key', function () {
    Native::test(PayloadListenerScreen::class)
        ->emitNative('ItemsReceived', ['items' => ['a', 'b'], 'total' => 2])
        ->assertSet('items', ['a', 'b']);
});
