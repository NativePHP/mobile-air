<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Events\Notification\NotificationTapped;

it('is marked for global dispatch', function () {
    expect(is_subclass_of(NotificationTapped::class, BroadcastsGlobally::class))->toBeTrue();
});

it('rebuilds a reply from its native payload', function () {
    $comp = new class extends NativeComponent {};
    $build = new ReflectionMethod(NativeComponent::class, 'buildEventInstance');
    $build->setAccessible(true);

    $event = $build->invoke($comp, NotificationTapped::class, [
        'action' => 'reply',
        'id' => 'msg-7',
        'data' => ['conversation' => '42'],
        'text' => 'On my way',
    ]);

    expect($event)->toBeInstanceOf(NotificationTapped::class)
        ->and($event->action)->toBe('reply')
        ->and($event->id)->toBe('msg-7')
        ->and($event->data)->toBe(['conversation' => '42'])
        ->and($event->text)->toBe('On my way');
});

it('treats a tap on a local notification on Android as a plain tap', function () {
    $comp = new class extends NativeComponent {};
    $build = new ReflectionMethod(NativeComponent::class, 'buildEventInstance');
    $build->setAccessible(true);

    $event = $build->invoke($comp, NotificationTapped::class, [
        'action' => 'default',
        'id' => null,
        'data' => ['notification_url' => '/reminders/3'],
    ]);

    expect($event->action)->toBe('default')
        ->and($event->id)->toBeNull()
        ->and($event->text)->toBeNull();
});

it('reaches app-wide listeners', function () {
    $comp = new class extends NativeComponent {};
    $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchGloballyIfMarked');
    $dispatch->setAccessible(true);

    $seen = null;
    Event::listen(NotificationTapped::class, function (NotificationTapped $e) use (&$seen) {
        $seen = $e->data['conversation'] ?? null;
    });

    $dispatch->invoke($comp, NotificationTapped::class, ['data' => ['conversation' => '42']]);

    expect($seen)->toBe('42');
});

it('binds the action and data to a screen\'s #[On] handler', function () {
    $comp = new class extends NativeComponent
    {
        public array $opened = [];

        #[On(NotificationTapped::class)]
        public function opened(string $action, array $data, ?string $text): void
        {
            $this->opened = [$action, $data, $text];
        }
    };

    $register = new ReflectionMethod(NativeComponent::class, 'registerNativeEventListeners');
    $register->setAccessible(true);
    $register->invoke($comp);

    $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchNativeEvent');
    $dispatch->setAccessible(true);
    $dispatch->invoke($comp, [
        'event' => NotificationTapped::class,
        'payload' => ['action' => 'mark-read', 'id' => 'n1', 'data' => ['conversation' => '9'], 'text' => null],
    ]);

    expect($comp->opened)->toBe(['mark-read', ['conversation' => '9'], null]);
});
