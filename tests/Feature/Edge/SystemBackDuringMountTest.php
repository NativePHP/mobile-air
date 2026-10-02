<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\FakeBridge;
use Tests\Fixtures\Edge\ChatListScreen;
use Tests\Fixtures\Edge\QueuedEventBridge;
use Tests\Fixtures\Edge\SlowChatScreen;
use Tests\Fixtures\Edge\StackChromeLayout;

/*
 * #471: native pushes a #[Lazy] screen's placeholder before mount() runs, so
 * the user can pop it while PHP is still busy. The screen swap then resets
 * the event queue, and the system back used to go with it: PHP published the
 * screen the user had just left and stayed on it.
 */

beforeEach(function () {
    NativeRouter::clearRoutes();
    NativeRouter::register('/', ChatListScreen::class, StackChromeLayout::class);
    NativeRouter::register('/chats/{id}', SlowChatScreen::class, StackChromeLayout::class);

    SlowChatScreen::$eventsDuringMount = [];
    SlowChatScreen::$log = [];

    $this->bridge = new QueuedEventBridge;
    app()->instance(FakeBridge::class, $this->bridge);

    $this->tapOpen = ['type' => 0, 'callback_id' => (new CallbackRegistry)->register('open')];

    // The user taps "Open chat" on the list.
    $this->bridge->post($this->tapOpen);
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

it('answers a system back sent while the next screen was still mounting', function () {
    SlowChatScreen::$eventsDuringMount = [['type' => NativeComponent::EVENT_SYSTEM_BACK]];

    (new NativeRouter)->start(ChatListScreen::class, [], '/');

    expect(SlowChatScreen::$log)->toBe(['mount', 'back'])
        ->and($this->bridge->stackFrames())->toBe([
            '/ Chat list',
            '/ Chat list',  // farewell frame as the list navigates
            '/chats/1',     // the placeholder native pushed
            '/ Chat list',  // back on the list, and the chat never published again
        ]);
});

it('still publishes the screen when nothing popped it', function () {
    (new NativeRouter)->start(ChatListScreen::class, [], '/');

    expect(SlowChatScreen::$log)->toBe(['mount'])
        ->and($this->bridge->stackFrames())->toContain('/chats/1 Chat loaded');
});

it('still drops a tap that was meant for the screen being left', function () {
    SlowChatScreen::$eventsDuringMount = [$this->tapOpen, ['type' => NativeComponent::EVENT_SYSTEM_BACK]];

    (new NativeRouter)->start(ChatListScreen::class, [], '/');

    // Replaying the tap on the list would have opened the chat a second time.
    expect(SlowChatScreen::$log)->toBe(['mount', 'back']);
});

it('keeps native events that arrive while the next screen is mounting', function () {
    SlowChatScreen::$eventsDuringMount = [['type' => NativeComponent::EVENT_NATIVE, 'event' => 'ping', 'payload' => []]];

    (new NativeRouter)->start(ChatListScreen::class, [], '/');

    expect(SlowChatScreen::$log)->toBe(['mount', 'ping']);
});
