<?php

use Native\Mobile\Testing\FakeBridge;

afterEach(function () {
    FakeBridge::disable();
});

it('refuses a page a bridge function of the async lane', function (array $call) {
    $bridge = FakeBridge::enable()->respondTo($call['method'], ['success' => true]);

    $this->postJson('/_native/api/call', $call)
        ->assertForbidden()
        ->assertJson(['status' => 'error', 'code' => 'INTERNAL_METHOD']);

    expect($bridge->callsTo($call['method']))->toBe([]);
})->with([
    'a dispatch with a quote in its id' => [['method' => 'AsyncTask.Dispatch', 'params' => ['id' => "it's"]]],
    'a completion under a name of its own' => [['method' => 'AsyncTask.Complete', 'params' => ['event' => '__deeplink', 'payload' => ['uri' => '/anywhere']]]],
]);

it('still passes a page any other bridge function', function () {
    $bridge = FakeBridge::enable()->respondTo('Dialog.Toast', ['success' => true]);

    $this->postJson('/_native/api/call', ['method' => 'Dialog.Toast', 'params' => ['message' => 'Saved']])
        ->assertOk()
        ->assertJson(['status' => 'success', 'data' => ['success' => true]]);

    expect($bridge->callsTo('Dialog.Toast'))->toHaveCount(1);
});
