<?php

namespace Native\Mobile\Events\Device;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\ThermalState;

/**
 * The device thermal state changed while the app was running.
 *
 * Fired from native (iOS `ProcessInfo.thermalStateDidChangeNotification` /
 * Android `PowerManager.OnThermalStatusChangedListener`) when the OS reports
 * a new status, and again on foreground if the value drifted while the app
 * was suspended (iOS `didBecomeActive` / Android `onResume`). Android 8–9
 * has no thermal API and never emits this.
 *
 * React in a component:
 *
 *     #[On(ThermalStateChanged::class)]
 *     public function onThermal(ThermalState $state, ThermalState $previous): void
 *     {
 *         if ($state->isWarmerThan($previous) && $state->isHot()) { ... }
 *         if ($state->isCoolerThan($previous)) { ... }
 *     }
 *
 * …or anywhere in the app (it also dispatches globally — see
 * [[BroadcastsGlobally]]):
 *
 *     Event::listen(ThermalStateChanged::class, fn ($e) => $e->isWarming());
 *
 * The query side (`Device::thermalState()`) is kept in sync off this event,
 * so reads stay fresh without a bridge round-trip.
 */
class ThermalStateChanged implements BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ThermalState $state,
        public ThermalState $previous,
    ) {}

    public function isWarming(): bool
    {
        return $this->state->isWarmerThan($this->previous);
    }

    public function isCooling(): bool
    {
        return $this->state->isCoolerThan($this->previous);
    }
}
