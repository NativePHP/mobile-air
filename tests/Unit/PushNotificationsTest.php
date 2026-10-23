<?php

use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Testing\FakeBridge;

afterEach(fn () => FakeBridge::disable());

it('clears the app badge through the native bridge', function () {
    $bridge = FakeBridge::enable();

    PushNotifications::clearBadge();

    $bridge->assertCalled(
        'PushNotification.ClearBadge',
        fn (array $params) => $params === []
    );
});
