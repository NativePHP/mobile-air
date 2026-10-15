<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Support\NativeCallbacks;

it('accepts the takenAt and location keys the camera sends with PhotoTaken', function () {
    Event::fake([PhotoTaken::class]);

    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => [
            'path' => '/tmp/captured.jpg',
            'mimeType' => 'image/jpeg',
            'id' => 'capture-1',
            'takenAt' => '2026-05-25T18:46:04Z',
            'latitude' => 51.5072,
            'longitude' => -0.1276,
        ],
    ])->assertOk()->assertJson(['success' => true]);

    Event::assertDispatched(PhotoTaken::class, function (PhotoTaken $event) {
        return $event->path === '/tmp/captured.jpg'
            && $event->id === 'capture-1'
            && $event->takenAt === '2026-05-25T18:46:04Z'
            && $event->latitude === 51.5072
            && $event->longitude === -0.1276;
    });
});

it('still builds PhotoTaken from a payload without the new keys', function () {
    Event::fake([PhotoTaken::class]);

    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['path' => '/tmp/captured.jpg'],
    ])->assertOk();

    Event::assertDispatched(PhotoTaken::class, function (PhotoTaken $event) {
        return $event->mimeType === 'image/jpeg'
            && $event->id === null
            && $event->takenAt === null
            && $event->latitude === null
            && $event->longitude === null;
    });
});

it('ignores payload keys the event constructor does not declare', function () {
    Event::fake([PhotoCancelled::class]);

    $this->postJson('/_native/api/events', [
        'event' => PhotoCancelled::class,
        'payload' => ['cancelled' => true, 'id' => 'capture-2', 'reason' => 'user'],
    ])->assertOk()->assertJson(['success' => true]);

    Event::assertDispatched(PhotoCancelled::class, fn (PhotoCancelled $event) => $event->id === 'capture-2');
});

it('passes a positional payload through unchanged', function () {
    Event::fake([PhotoTaken::class]);

    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['/tmp/captured.png', 'image/png', 'capture-3'],
    ])->assertOk();

    Event::assertDispatched(PhotoTaken::class, function (PhotoTaken $event) {
        return $event->path === '/tmp/captured.png'
            && $event->mimeType === 'image/png'
            && $event->id === 'capture-3';
    });
});

it('fires the fluent callback when the payload carries extra keys', function () {
    $received = new ArrayObject;

    NativeCallbacks::register('capture-4', PhotoTaken::class, static function (PhotoTaken $event) use ($received) {
        $received[] = $event;
    }, durable: false);

    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['path' => '/tmp/captured.jpg', 'id' => 'capture-4', 'takenAt' => '2026-05-25T18:46:04Z', 'orientation' => 6],
    ])->assertOk()->assertJson(['success' => true, 'callback' => true]);

    expect($received)->toHaveCount(1)
        ->and($received[0]->takenAt)->toBe('2026-05-25T18:46:04Z');
});

it('reports failure for an unknown event class', function () {
    $this->postJson('/_native/api/events', [
        'event' => 'App\\Events\\DoesNotExist',
        'payload' => ['foo' => 'bar'],
    ])->assertOk()->assertJson(['success' => false]);
});
