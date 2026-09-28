<?php

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Tests\Fixtures\Edge\PollScreen;

/*
 * What the runloop does while it has nothing to do. Every case here is a
 * screen sitting still: the cost of doing nothing is the whole subject.
 */

function idleTimeout(NativeComponent $screen): int
{
    return (new ReflectionMethod(NativeComponent::class, 'nextEventTimeout'))->invoke($screen);
}

function idleProperty(NativeComponent $screen, string $name): mixed
{
    return (new ReflectionProperty(NativeComponent::class, $name))->getValue($screen);
}

function idleSetProperty(NativeComponent $screen, string $name, mixed $value): void
{
    (new ReflectionProperty(NativeComponent::class, $name))->setValue($screen, $value);
}

// ── The error screen ─────────────────────────────────────────────

it('blocks instead of waking while the error screen is up', function () {
    $screen = new PollScreen;

    // Prime, then age every deadline past due — which is the state the loop is
    // always in once it stops running polls.
    idleTimeout($screen);
    $defs = idleProperty($screen, 'pollDefinitions');
    foreach ($defs as $i => $def) {
        $defs[$i]['next'] = microtime(true) * 1000 - 5000;
    }
    idleSetProperty($screen, 'pollDefinitions', $defs);

    // Without the error screen this is the ordinary overdue case: don't sleep.
    expect(idleTimeout($screen))->toBe(0);

    idleSetProperty($screen, 'nativeHasError', true);

    // With it, runDuePolls() is skipped, so nothing will ever advance those
    // deadlines — a finite timeout here is a wake-up that can only find the
    // same overdue deadline again. ~1000 times a second, until the user leaves.
    expect(idleTimeout($screen))->toBe(-1);
});

it('still stamps poll deadlines on a screen that enters the loop in error', function () {
    $screen = new PollScreen;
    idleSetProperty($screen, 'nativeHasError', true);

    expect(idleTimeout($screen))->toBe(-1);

    // A screen whose mount() failed starts here. If the deadlines were only
    // stamped after the overlay is dismissed, a #[Poll(1000)] that IS the
    // screen's refresh would idle a further interval after recovery instead of
    // firing straight away.
    $defs = idleProperty($screen, 'pollDefinitions');
    expect($defs)->toBeArray()->not->toBeEmpty();

    $soonest = min(array_column($defs, 'next')) - microtime(true) * 1000;
    expect($soonest)->toBeLessThanOrEqual(1000)->toBeGreaterThan(0);
});

it('leaves a screen with no polls blocking, as it always did', function () {
    $screen = new class extends NativeComponent
    {
        public function render(): Element
        {
            return Column::make();
        }
    };

    expect(idleTimeout($screen))->toBe(-1);
});

// ── An overdue poll ──────────────────────────────────────────────

it('does not sleep in front of a poll it is already late for', function () {
    $screen = new PollScreen;
    idleTimeout($screen);

    $defs = idleProperty($screen, 'pollDefinitions');
    $defs[0]['next'] = microtime(true) * 1000 - 4;      // 4ms late already
    idleSetProperty($screen, 'pollDefinitions', $defs);

    // The 1ms floor used to apply here too, adding a millisecond of sleep in
    // front of work the loop was already behind on.
    expect(idleTimeout($screen))->toBe(0);
});

it('still waits out the remaining interval for a poll that is not due', function () {
    $screen = new PollScreen;

    // Freshly primed: the soonest is the 1s tick, so the wait reflects that.
    expect(idleTimeout($screen))->toBeGreaterThan(900)->toBeLessThanOrEqual(1000);
});

it('rounds up rather than down for a deadline under a millisecond away', function () {
    $screen = new PollScreen;
    idleTimeout($screen);

    $defs = idleProperty($screen, 'pollDefinitions');
    foreach ($defs as $i => $def) {
        $defs[$i]['next'] = microtime(true) * 1000 + 0.4;
    }
    idleSetProperty($screen, 'pollDefinitions', $defs);

    // A 0 here would be a pass that runDuePolls() declines to service, since
    // the deadline has not actually arrived — a busy loop with nothing to do.
    expect(idleTimeout($screen))->toBe(1);
});

// ── The dispatched-event log ─────────────────────────────────────

it('keeps the dispatched-event log bounded on a long-lived screen', function () {
    $screen = new PollScreen;
    $cap = (new ReflectionClassConstant(NativeComponent::class, 'MAX_RECORDED_DISPATCHES'))->getValue();
    $flush = new ReflectionMethod(NativeComponent::class, 'flushDispatchedEvents');

    // Stand in for a screen that has been open a long time.
    idleSetProperty($screen, 'nativeDispatchedComponentEvents', array_fill(0, $cap, ['name' => 'old', 'params' => []]));

    $screen->dispatch('fresh', ['n' => 1]);
    $flush->invoke($screen);

    $log = idleProperty($screen, 'nativeDispatchedComponentEvents');

    // Bounded, and it is the OLDEST that goes: assertions look for a recent
    // dispatch, never the whole history.
    expect($log)->toHaveCount($cap)
        ->and(end($log)['name'])->toBe('fresh');
});
