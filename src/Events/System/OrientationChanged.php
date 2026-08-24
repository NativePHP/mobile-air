<?php

namespace Native\Mobile\Events\System;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The screen orientation (portrait / landscape) changed while the app was
 * running — the user rotated the device. Only fires when the app allows more
 * than one orientation (`nativephp.orientation` config). Fired from native
 * (iOS window size change / Android `onConfigurationChanged` orientation).
 *
 * React in a component:
 *
 *     #[On(OrientationChanged::class)]
 *     public function rotated(string $orientation): void { ... } // 'portrait' | 'landscape'
 *
 * …or anywhere in the app (it also dispatches globally — see
 * [[BroadcastsGlobally]]):
 *
 *     Event::listen(OrientationChanged::class, fn ($e) => ...);
 *
 * The query side (`System::orientation()` / `System::isLandscape()`) is kept
 * in sync off this event, so reads stay fresh without a bridge round-trip.
 */
class OrientationChanged implements BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    public function __construct(
        /** 'portrait' | 'landscape' */
        public string $orientation,
    ) {}
}
