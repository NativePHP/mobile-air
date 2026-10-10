<?php

use Livewire\Features\SupportEvents\BaseOn;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\LegacyListenersScreen;
use Tests\Fixtures\Edge\PingReceived;
use Tests\Fixtures\Edge\SeveralListenersScreen;

// ── Native events ───────────────────────────────────

it('runs every method listening for one native event, in declared order, and renders once', function () {
    Native::test(SeveralListenersScreen::class)
        ->emitNative(PingReceived::class, ['message' => 'yo'])
        ->assertSet('log', ['updateBadge:yo', 'reloadList:yo'])
        ->assertRenderCount(2);
});

it('gives each listener its own parameters from the payload, cast to their types', function (string $event, array $payload, array $received) {
    $screen = Native::test(SeveralListenersScreen::class)->emitNative($event, $payload);

    expect($screen->get('reading'))->toBe($received);
})->with([
    'a value for each parameter' => [
        'ReadingReceived',
        ['count' => '3', 'label' => 7, 'enabled' => '0'],
        ['count' => 3, 'label' => '7', 'enabled' => false],
    ],
    'a null for a parameter that does not allow null, which is cast as well' => [
        'ReadingReceived',
        ['count' => null, 'label' => null, 'enabled' => null],
        ['count' => 0, 'label' => '', 'enabled' => false],
    ],
    'a null for a parameter that allows null, which stays a null' => [
        'PartialReadingReceived',
        ['count' => null, 'label' => null, 'enabled' => null, 'level' => null],
        ['count' => null, 'label' => null, 'enabled' => null, 'level' => null],
    ],
    // Only the cast makes a float of an empty string, which PHP itself would refuse.
    'a value for a parameter that allows null, which is still cast' => [
        'PartialReadingReceived',
        ['count' => '3', 'label' => 7, 'enabled' => '0', 'level' => ''],
        ['count' => 3, 'label' => '7', 'enabled' => false, 'level' => 0.0],
    ],
]);

it('lets one method listen for two different events', function () {
    Native::test(SeveralListenersScreen::class)
        ->emitNative('DoorOpened', ['state' => 'open'])
        ->emitNative('DoorClosed', ['state' => 'closed'])
        ->assertSet('log', ['trackDoor:open', 'trackDoor:closed']);
});

it('runs every method listening through a plugin attribute that extends On without the filter', function () {
    Native::test(SeveralListenersScreen::class)
        ->emitNative('SignalReceived')
        ->assertSet('log', ['plotSignal', 'storeSignal']);
});

it('runs the remaining listeners after an earlier one navigates away', function () {
    Native::test(SeveralListenersScreen::class)
        ->emitNative('SessionExpired')
        ->assertNavigatedTo('/login')
        ->assertSet('log', ['leaveScreen', 'clearDraft']);
});

it('lets a listener exception propagate and skips the listeners declared after it', function () {
    $screen = Native::test(SeveralListenersScreen::class);

    expect(fn () => $screen->emitNative('SyncFailed'))
        ->toThrow(RuntimeException::class, 'Listener failed');

    expect($screen->get('log'))->toBe(['beforeFailure']);
});

// ── Legacy attribute ────────────────────────────────

it('runs every method listening through the legacy OnNative attribute, which hears all next to a filtered On', function () {
    Native::test(LegacyListenersScreen::class)
        ->emitNative(PingReceived::class, ['message' => 'yo'])
        ->assertSet('log', ['updateBadge:yo', 'reloadList:yo'])
        ->emitNative(PingReceived::class, ['message' => 'filtered'])
        ->assertSet('log', ['updateBadge:yo', 'reloadList:yo', 'updateBadge:filtered', 'reloadList:filtered', 'filteredPing:filtered']);
})->skip(! class_exists(BaseOn::class), 'Livewire is not installed, so the legacy #[OnNative] scan is off.');
