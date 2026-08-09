<?php

use Native\Mobile\Concerns\ManagesDevtoolsListener;

function listenerHost(): object
{
    return new class
    {
        use ManagesDevtoolsListener;

        public function covers(?string $bound, string $required): bool
        {
            return $this->devtoolsHostCovers($bound, $required);
        }
    };
}

it('reuses a loopback listener for a simulator', function () {
    expect(listenerHost()->covers('127.0.0.1', '127.0.0.1'))->toBeTrue();
});

it('refuses a loopback listener for a device that needs the LAN', function () {
    // The device is provisioned with the Mac's LAN address; a listener bound
    // to loopback would never receive those POSTs, and the failure is silent.
    expect(listenerHost()->covers('127.0.0.1', '0.0.0.0'))->toBeFalse();
});

it('reuses an all-interfaces listener for either target', function () {
    expect(listenerHost()->covers('0.0.0.0', '127.0.0.1'))->toBeTrue()
        ->and(listenerHost()->covers('0.0.0.0', '0.0.0.0'))->toBeTrue();
});

it('refuses a handshake that never recorded its host', function () {
    // Older listeners predate the host field — safer to replace than to
    // assume, since assuming wrong fails silently.
    expect(listenerHost()->covers(null, '127.0.0.1'))->toBeFalse();
});
