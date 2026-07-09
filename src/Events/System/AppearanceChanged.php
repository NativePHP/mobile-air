<?php

namespace Native\Mobile\Events\System;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The system appearance (light / dark) changed while the app was running —
 * the user flipped it in Control Center / Quick Settings, or it auto-switched
 * at sunset. Fired from native (iOS `colorScheme` change / Android
 * `onConfigurationChanged` night bit).
 *
 * React in a component:
 *
 *     #[On(AppearanceChanged::class)]
 *     public function themed(string $mode): void { ... } // 'light' | 'dark'
 *
 * …or anywhere in the app (it also dispatches globally — see
 * [[BroadcastsGlobally]]):
 *
 *     Event::listen(AppearanceChanged::class, fn ($e) => ...);
 *
 * The query side (`System::appearance()` / `System::isDarkMode()`) is kept in
 * sync off this event, so reads stay fresh without a bridge round-trip.
 */
class AppearanceChanged implements BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    public function __construct(
        /** 'light' | 'dark' */
        public string $mode,
    ) {}
}
