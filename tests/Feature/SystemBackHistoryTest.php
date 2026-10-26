<?php

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\System;
use Native\Mobile\Testing\FakeBridge;

/**
 * On iOS a long press on the back button opens a history menu, and picking
 * an entry pops several screens at once. Native navigation posts one system
 * back per screen it popped. PHP has to pop that many too, or its stack and
 * the native one disagree and the next frame pushes a screen back on.
 */
abstract class HistoryScreen extends NativeComponent
{
    /** @var list<class-string> */
    public static array $drawn = [];

    public function render(): Element
    {
        self::$drawn[] = static::class;

        return Column::make();
    }
}

class HistoryRootScreen extends HistoryScreen {}

class HistoryListScreen extends HistoryScreen {}

class HistoryDetailScreen extends HistoryScreen {}

class HistoryCommentScreen extends HistoryScreen {}

class HistoryDraftScreen extends HistoryScreen
{
    /** Keeps the user here, as a screen guarding an unsaved draft would. */
    public function onBackPressed(): void {}
}

/**
 * Queues events like the C bridge does, and shuts the app down when a screen
 * waits on an empty queue, so the router's session ends with the test.
 */
class HistoryBridge extends FakeBridge
{
    public function elementWaitEvent(int $timeoutMs): ?array
    {
        $event = parent::elementWaitEvent($timeoutMs);

        if ($event === null && $timeoutMs !== 0) {
            return ['type' => NativeComponent::EVENT_SHUTDOWN];
        }

        return $event;
    }
}

/**
 * Open $top on a stack of the given screens, with the given number of system
 * backs already queued, and return the screens that drew a frame.
 *
 * @param  list<string>  $below
 * @return list<class-string>
 */
function popThroughHistory(array $below, string $top, int $backs): array
{
    $bridge = new HistoryBridge;
    app()->instance(FakeBridge::class, $bridge);

    for ($i = 0; $i < $backs; $i++) {
        $bridge->queueEvent(['type' => NativeComponent::EVENT_SYSTEM_BACK]);
    }

    $router = new NativeRouter;
    $router->preloadStack(array_map(fn (string $uri) => ['uri' => $uri], $below));
    $router->start(NativeRouter::resolve($top)['class'], [], $top);

    return HistoryScreen::$drawn;
}

beforeEach(function () {
    NativeRouter::clearRoutes();
    NativeRouter::register('/root', HistoryRootScreen::class);
    NativeRouter::register('/list', HistoryListScreen::class);
    NativeRouter::register('/detail', HistoryDetailScreen::class);
    NativeRouter::register('/comment', HistoryCommentScreen::class);
    NativeRouter::register('/draft', HistoryDraftScreen::class);

    HistoryScreen::$drawn = [];
    System::rememberPlatform(null);
});

afterEach(function () {
    NativeRouter::clearRoutes();
    FakeBridge::disable();
});

it('pops every level native navigation popped, without drawing the screens in between', function () {
    $drawn = popThroughHistory(['/root', '/list', '/detail'], '/comment', backs: 3);

    expect($drawn)->toBe([HistoryCommentScreen::class, HistoryRootScreen::class]);
});

it('never answers a queued system back on the root screen', function () {
    $drawn = popThroughHistory(['/root'], '/list', backs: 3);

    expect($drawn)->toBe([HistoryListScreen::class, HistoryRootScreen::class]);
});

it('stops popping at a screen that answers its back by staying', function () {
    $drawn = popThroughHistory(['/root', '/list', '/draft'], '/comment', backs: 3);

    // The draft screen stays and draws. The back left over for the list
    // screen is dropped, so the list only draws once the session unwinds.
    expect($drawn)->toBe([
        HistoryCommentScreen::class,
        HistoryDraftScreen::class,
        HistoryListScreen::class,
        HistoryRootScreen::class,
    ]);
});
