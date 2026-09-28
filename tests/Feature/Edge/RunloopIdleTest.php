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

    // Without the error screen this is the ordinary overdue case: wake soon.
    expect(idleTimeout($screen))->toBe(1);

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
