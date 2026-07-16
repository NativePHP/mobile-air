<?php

namespace Native\Mobile\Events\Motion;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
 * A shake carries no reliable magnitude on iOS, so the payload is minimal —
 * `id` is an optional correlation token if a future emitter wants to set one.
 */
class ShakeDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ?string $id = null
    ) {}
}
