<?php

namespace Native\Mobile\Events\Camera;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Camera permission denied event
 *
 * This event is dispatched from native code (Kotlin/Swift) via JavaScript injection,
 * directly triggering Livewire listeners with #[On('native:Native\Mobile\Events\Camera\PermissionDenied')]
 */
class PermissionDenied
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $action  The action that was attempted: 'photo', 'video'
     * @param  string|null  $id  Optional tracking ID
     */
    public function __construct(
        public string $action,
        public ?string $id = null
    ) {}
}
