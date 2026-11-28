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

it('sets the badge through core\'s own bridge function, not the push plugin\'s', function () {
    $bridge = FakeBridge::enable();

    PushNotifications::setBadge(3);
    PushNotifications::setBadge(0);

    $bridge->assertCalled('Notification.SetBadge', fn (array $params) => $params === ['count' => 3]);
    $bridge->assertCalled('Notification.SetBadge', fn (array $params) => $params === ['count' => 0]);
    $bridge->assertNotCalled('PushNotification.ClearBadge');
});

it('rejects a negative badge count before calling native', function () {
    $bridge = FakeBridge::enable();

    expect(fn () => PushNotifications::setBadge(-1))->toThrow(InvalidArgumentException::class);

    $bridge->assertNotCalled('Notification.SetBadge');
});

it('removes one thread\'s delivered notifications', function () {
    $bridge = FakeBridge::enable();

    PushNotifications::removeDelivered('conversation-42');

    $bridge->assertCalled('Notification.RemoveDelivered', fn (array $params) => $params === ['thread' => 'conversation-42']);
});

it('refuses to remove notifications for an empty thread', function () {
    $bridge = FakeBridge::enable();

    expect(fn () => PushNotifications::removeDelivered(''))->toThrow(InvalidArgumentException::class);

    $bridge->assertNotCalled('Notification.RemoveDelivered');
});
