<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeDumpException;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ScriptedEventBridge;

/**
 * The runloop renders after every event, including ones that change nothing
 * on screen. A frame that matches the last one published isn't published
 * again, unless something may have swapped the native tree out since.
 */
class UnchangedFrameScreen extends NativeComponent
{
    public int $count = 0;

    /** State the screen doesn't draw, like a page fetched ahead of the scroll. */
    public array $held = [];

    public string $rowKey = 'a';

    public bool $memo = false;

    public function render(): Element
    {
        return Column::make(
            Text::make("Count {$this->count}")->onPress('increment'),
            Text::make('Same')->onPress('same'),
            Text::make('Hold')->onPress('hold'),
            Text::make('Rekey')->onPress('rekey'),
            Text::make('Reset')->onPress('resetRegion'),
            Text::make('Explode')->onPress('explode'),
            Text::make('Dump')->onPress('dump'),
            Column::make(Text::make('Row'))->key($this->rowKey),
        );
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function same(): void
    {
        $this->count = $this->count;
    }

    public function hold(): void
    {
        $this->held[] = 'next page';
    }

    public function rekey(): void
    {
        $this->rowKey = 'b';
    }

    public function resetRegion(): void
    {
        nativephp_element_reset();
    }

    public function explode(): void
    {
        throw new RuntimeException('boom');
    }

    public function dump(): void
    {
        throw new NativeDumpException(['held' => $this->held], __FILE__, __LINE__);
    }

    protected function subtreeMemoEnabled(): bool
    {
        return $this->memo;
    }
}

class UnchangedFullFrameScreen extends UnchangedFrameScreen
{
    protected bool $forceFullFrames = true;
}

function tapEvent(string $method): array
{
    return ['type' => 0, 'callback_id' => (new CallbackRegistry)->register($method)];
}

function taskFinishedEvent(string $id): array
{
    return ['type' => NativeComponent::EVENT_NATIVE, 'event' => AsyncTaskFinished::class, 'payload' => ['id' => $id, 'result' => 'page 2']];
}

/**
 * Run the screen's loop over these events, then shut it down.
 *
 * @param  list<array<string, mixed>|null>  $events
 */
function runScreenOver(NativeComponent $screen, array $events): ScriptedEventBridge
{
    $bridge = new ScriptedEventBridge($events);
    app()->instance(FakeBridge::class, $bridge);

    $screen->runLoop();

    return $bridge;
}

/** @return list<string> */
function textsInFrame(array $node): array
{
    $texts = isset($node['props']['text']) ? [$node['props']['text']] : [];

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...textsInFrame($child)];
    }

    return $texts;
}

afterEach(function () {
    NativeCallbacks::flush();
    FakeBridge::disable();
});

it('publishes the first frame and every change, and nothing for events that change nothing', function () {
    NativeCallbacks::register('fetch-ahead', AsyncTaskFinished::class, function (mixed $page): void {
        $this->held[] = $page;
    }, durable: false);

    $bridge = runScreenOver(new UnchangedFrameScreen, [
        null,                         // idle tick
        tapEvent('same'),                // handler runs, nothing changes
        taskFinishedEvent('no-callback'),  // a task nobody listens for
        taskFinishedEvent('fetch-ahead'),  // a callback that stores what isn't drawn
        tapEvent('hold'),
        tapEvent('increment'),           // the only visible change
        null,
    ]);

    expect($bridge->publishes)->toHaveCount(2)
        ->and(textsInFrame($bridge->publishes[0]))->toContain('Count 0')
        ->and(textsInFrame($bridge->publishes[1]))->toContain('Count 1');
});

it('publishes a frame whose only change is a node id', function () {
    $bridge = runScreenOver(new UnchangedFrameScreen, [tapEvent('rekey')]);

    expect($bridge->publishes)->toHaveCount(2)
        ->and($bridge->publishes[0]['children'][7]['id'])->not->toBe($bridge->publishes[1]['children'][7]['id']);
});

it('publishes the same frame again once the native tree may have been swapped out', function () {
    $bridge = runScreenOver(new UnchangedFrameScreen, [
        tapEvent('resetRegion'),  // a reset clears the native tree and the C shadow
        null,
    ]);

    expect($bridge->resetCount)->toBe(1)
        ->and($bridge->publishes)->toHaveCount(2)
        ->and($bridge->publishes[1])->toBe($bridge->publishes[0]);
});

it('publishes the same frame again after the epoch moves for any other reason', function () {
    $bridge = new class([null, null, null]) extends ScriptedEventBridge
    {
        public function elementWaitEvent(int $timeoutMs): ?array
        {
            // What a refused frame or nativephp_force_full_frame() does on device.
            if (count($this->publishes) === 1) {
                $this->forceFullFrameEpoch++;
            }

            return parent::elementWaitEvent($timeoutMs);
        }
    };
    app()->instance(FakeBridge::class, $bridge);

    (new UnchangedFrameScreen)->runLoop();

    expect($bridge->publishes)->toHaveCount(2);
});

it('redraws the screen when the error or dump screen is dismissed, though its frame is unchanged', function (string $method, string $heading) {
    $bridge = runScreenOver(new UnchangedFrameScreen, [tapEvent($method), ['type' => 8]]);

    expect($bridge->publishes)->toHaveCount(3)
        ->and(textsInFrame($bridge->publishes[1]))->toContain($heading)
        ->and($bridge->publishes[2])->toBe($bridge->publishes[0]);
})->with([
    'error screen' => ['explode', 'Something went wrong'],
    'dump screen' => ['dump', 'dd()'],
]);

it('publishes the first frame each time the screen comes back', function () {
    $screen = new UnchangedFrameScreen;

    runScreenOver($screen, [null]);
    $bridge = runScreenOver($screen, [null]);

    expect($bridge->publishes)->toHaveCount(1);
});

it('holds back unchanged frames on a screen that forces full frames', function () {
    $screen = new UnchangedFullFrameScreen;
    $screen->memo = true;

    $bridge = runScreenOver($screen, [null, tapEvent('same'), tapEvent('increment'), null]);

    expect($bridge->publishes)->toHaveCount(2)
        ->and(textsInFrame($bridge->publishes[1]))->toContain('Count 1');
});

it('holds back unchanged frames under subtree memo, but never the heartbeat', function () {
    $screen = new UnchangedFrameScreen;
    $screen->memo = true;

    // 121 renders: the first frame, then the heartbeat that drops the hash store.
    $bridge = runScreenOver($screen, array_fill(0, 120, null));

    expect($bridge->publishes)->toHaveCount(2)
        ->and($bridge->publishes[1]['flags'] ?? 0)->toBe(0);
});

it('publishes every frame under Jump', function () {
    putenv('JUMP_BRIDGE_PORT=3001');

    try {
        $bridge = runScreenOver(new UnchangedFrameScreen, [null, tapEvent('same')]);
    } finally {
        putenv('JUMP_BRIDGE_PORT');
    }

    expect($bridge->publishes)->toHaveCount(3);
});

it('holds back unchanged frames in the testing harness too', function () {
    $screen = Native::test(UnchangedFrameScreen::class)->tap('Same')->tap('Hold');

    expect($screen->bridge()->publishes)->toHaveCount(1);

    $screen->tap('Count 0')->assertSee('Count 1');

    expect($screen->bridge()->publishes)->toHaveCount(2);
});
