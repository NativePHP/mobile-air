<?php

use Native\Mobile\ExecutionContext;
use Native\Mobile\Facades\ExecutionContext as ExecutionContextFacade;
use Native\Mobile\Testing\FakeBridge;

// A headless iOS background cold launch — iOS woke the process for a
// BGTaskScheduler task while the device sat locked.
function headlessSnapshot(array $overrides = []): array
{
    return array_merge([
        'launch' => 'background',
        'state' => 'background',
        'foreground' => false,
        'active' => false,
        'has_become_active' => false,
        'headless' => true,
        'protected_data_available' => false,
        'interactive_boot_started' => false,
    ], $overrides);
}

// ── Reading the native state ────────────────────────

it('reports a headless background launch from the bridge', function () {
    FakeBridge::enable()->respondTo('System.GetExecutionContext', headlessSnapshot());

    $context = new ExecutionContext;

    expect($context->isHeadless())->toBeTrue()
        ->and($context->launchedInBackground())->toBeTrue()
        ->and($context->isBackground())->toBeTrue()
        ->and($context->isForeground())->toBeFalse()
        ->and($context->isActive())->toBeFalse()
        ->and($context->hasBecomeActive())->toBeFalse()
        ->and($context->state())->toBe('background')
        ->and($context->launch())->toBe('background');
});

it('reports protected data as unavailable while the device is locked', function () {
    FakeBridge::enable()->respondTo('System.GetExecutionContext', headlessSnapshot());

    expect((new ExecutionContext)->isProtectedDataAvailable())->toBeFalse();
});

it('reports the interactive boot as withheld during a headless launch', function () {
    FakeBridge::enable()->respondTo('System.GetExecutionContext', headlessSnapshot());

    expect((new ExecutionContext)->interactiveBootStarted())->toBeFalse();
});

it('reports an ordinary foreground app as interactive', function () {
    FakeBridge::enable()->respondTo('System.GetExecutionContext', [
        'launch' => 'foreground',
        'state' => 'active',
        'foreground' => true,
        'active' => true,
        'has_become_active' => true,
        'headless' => false,
        'protected_data_available' => true,
        'interactive_boot_started' => true,
    ]);

    $context = new ExecutionContext;

    expect($context->isHeadless())->toBeFalse()
        ->and($context->isActive())->toBeTrue()
        ->and($context->isForeground())->toBeTrue()
        ->and($context->isBackground())->toBeFalse()
        ->and($context->launchedInBackground())->toBeFalse()
        ->and($context->isProtectedDataAvailable())->toBeTrue();
});

it('treats a foregrounded-but-inactive app as still on screen', function () {
    FakeBridge::enable()->respondTo('System.GetExecutionContext', headlessSnapshot([
        'state' => 'inactive',
        'foreground' => true,
        'has_become_active' => true,
        'headless' => false,
    ]));

    $context = new ExecutionContext;

    expect($context->isForeground())->toBeTrue()
        ->and($context->isBackground())->toBeFalse()
        ->and($context->isActive())->toBeFalse();
});

// ── Degrading safely ────────────────────────────────

it('reports an interactive process when the bridge has no answer', function () {
    // A native shell built before System.GetExecutionContext existed: the
    // call goes out but comes back empty. Upgrading the PHP package alone
    // must never flip an app into believing it is headless.
    FakeBridge::enable();

    $context = new ExecutionContext;

    expect($context->isHeadless())->toBeFalse()
        ->and($context->isForeground())->toBeTrue()
        ->and($context->isActive())->toBeTrue()
        ->and($context->isProtectedDataAvailable())->toBeTrue()
        ->and($context->interactiveBootStarted())->toBeTrue()
        ->and($context->state())->toBe('active')
        ->and($context->launch())->toBe('foreground');
});

it('fills keys the native side did not report', function () {
    FakeBridge::enable()->respondTo('System.GetExecutionContext', [
        'headless' => true,
        'protected_data_available' => false,
    ]);

    $context = new ExecutionContext;

    expect($context->isHeadless())->toBeTrue()
        ->and($context->isProtectedDataAvailable())->toBeFalse()
        // Unreported keys fall back to the interactive defaults.
        ->and($context->state())->toBe('active');
});

// ── Wiring ──────────────────────────────────────────

it('resolves through the facade', function () {
    FakeBridge::enable()->respondTo('System.GetExecutionContext', headlessSnapshot());

    expect(ExecutionContextFacade::isHeadless())->toBeTrue()
        ->and(ExecutionContextFacade::all())->toBe(headlessSnapshot());
});

it('re-reads the bridge on every call so a long-lived screen never goes stale', function () {
    $bridge = FakeBridge::enable();
    $bridge->respondTo('System.GetExecutionContext', headlessSnapshot());

    $context = new ExecutionContext;
    expect($context->isForeground())->toBeFalse();

    $bridge->respondTo('System.GetExecutionContext', headlessSnapshot([
        'state' => 'active',
        'foreground' => true,
        'active' => true,
        'has_become_active' => true,
        'headless' => false,
    ]));

    expect($context->isForeground())->toBeTrue()
        ->and($context->isHeadless())->toBeFalse();
});
