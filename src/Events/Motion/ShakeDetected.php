<?php

namespace Native\Mobile\Events\Motion;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * Device shake detected.
 *
 * Dispatched from native code (iOS `motionEnded(.motionShake)` / Android
 * accelerometer) over the native-event channel. Handle it in a
 * NativeComponent with the `#[On(ShakeDetected::class)]` attribute:
 *
 *     #[On(ShakeDetected::class)]
 *     public function onShake(): void { ... }
 *
 * …or anywhere via `Event::listen(ShakeDetected::class, ...)`. A shake is a
 * system-level signal with no owning component, so it broadcasts globally —
 * webview screens already did this through POST /_native/api/events, and
 * the BroadcastsGlobally tag gives EDGE screens the same reach.
 *
 * A shake carries no reliable magnitude on iOS, so the payload is minimal —
 * `id` is an optional correlation token if a future emitter wants to set one.
 */
class ShakeDetected implements BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ?string $id = null
    ) {}
}
