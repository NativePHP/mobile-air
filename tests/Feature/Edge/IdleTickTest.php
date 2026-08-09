<?php

use Native\Mobile\Contracts\EdgeTicker;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\FakeBridge;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\PollScreen;

/**
 * The EDGE loop caps its event wait at 250ms whenever an EdgeTicker is
 * bound, so the devtools ticker gets idle turns. The cap must NOT make the
 * loop re-render: before the idle branch was split out, every timeout fell
 * through to the top of the loop and republished the whole tree ~4x/second
 * on a screen nobody was touching.
 *
 * FakeBridge's wait_event always returns an idle null, so these tickers
 * stop the component after a fixed number of turns to bound the loop.
 */
function tickerStopping(int $stopAfter, callable $mutates): EdgeTicker
{
    return new class($stopAfter, $mutates) implements EdgeTicker
    {
        public int $ticks = 0;

        public function __construct(private int $stopAfter, private $mutates) {}

        public function tick(NativeComponent $component): bool
        {
            $this->ticks++;

            if ($this->ticks >= $this->stopAfter) {
                $component->stop();
            }

            return ($this->mutates)($this->ticks);
        }
    };
}

function publishCountFor(NativeComponent $component): int
{
    return count(FakeBridge::current()->publishes);
}

/** Reach a private loop internal — bound to the declaring class, not the fixture. */
function loopInternal(NativeComponent $component, string $method): mixed
{
    return Closure::bind(
        fn () => $this->{$method}(),
        $component,
        NativeComponent::class,
    )();
}

it('does not re-render on idle ticks when the ticker reports no change', function () {
    FakeBridge::enable();

    $ticker = tickerStopping(5, fn () => false);
    app()->instance(EdgeTicker::class, $ticker);

    $screen = new CounterScreen;
    $screen->run();

    // The ticker really did get its turns — otherwise "no re-renders" would
    // pass trivially by the tick never running at all.
    expect($ticker->ticks)->toBe(5);

    // One frame: the initial render before the first wait. Five idle ticks
    // afterwards produced nothing. Pre-fix this was 5 — one render per tick,
    // measured by reverting the inner loop and re-running this test.
    expect(publishCountFor($screen))->toBe(1);
});

it('re-renders exactly once when the ticker reports it mutated state', function () {
    FakeBridge::enable();

    // True on the first turn only — a single synthetic tap, then quiet.
    $ticker = tickerStopping(4, fn (int $n) => $n === 1);
    app()->instance(EdgeTicker::class, $ticker);

    $screen = new CounterScreen;
    $screen->run();

    expect($ticker->ticks)->toBe(4);

    // Initial frame + exactly one repaint for the mutating tick.
    expect(publishCountFor($screen))->toBe(2);
});

it('keeps the block-forever wait when no ticker is bound', function () {
    FakeBridge::enable();

    expect(app()->bound(EdgeTicker::class))->toBeFalse();

    $screen = new CounterScreen;

    // -1 means "sleep until a real event arrives" — the pre-PR behaviour
    // every non-debug build runs on. A poll-free screen must still get it.
    expect(loopInternal($screen, 'nextEventTimeout'))->toBe(-1);
});

it('caps the wait only while a ticker is bound', function () {
    FakeBridge::enable();

    $screen = new CounterScreen;

    app()->instance(EdgeTicker::class, tickerStopping(1, fn () => false));
    expect(loopInternal($screen, 'nextEventTimeout'))->toBe(250);

    app()->forgetInstance(EdgeTicker::class);
    expect(loopInternal($screen, 'nextEventTimeout'))->toBe(-1);
});

it('reports a fired poll so the loop still repaints', function () {
    FakeBridge::enable();

    $screen = new PollScreen;

    // The loop always asks for the timeout first, which is what lazily
    // builds the poll definitions and schedules them at now + interval.
    loopInternal($screen, 'nextEventTimeout');

    // Nothing is due yet, so an idle tick must NOT force a repaint.
    expect(loopInternal($screen, 'runDuePolls'))->toBeFalse();

    // Bring every deadline into the past, as waiting out the interval would.
    Closure::bind(function () {
        foreach ($this->pollDefinitions as $i => $def) {
            $this->pollDefinitions[$i]['next'] = 0;
        }
    }, $screen, NativeComponent::class)();

    // A due poll must report "re-render" — otherwise a #[Poll] screen would
    // silently stop refreshing the moment the idle branch stopped rendering.
    expect(loopInternal($screen, 'runDuePolls'))->toBeTrue();
});
