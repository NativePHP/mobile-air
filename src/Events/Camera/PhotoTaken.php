<?php

namespace Native\Mobile\Events\Camera;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * Photo capture completed event
 *
 * This event is dispatched from native code (Kotlin/Swift) via JavaScript injection,
 * directly triggering Livewire listeners with #[On('native:Native\Mobile\Events\Camera\PhotoTaken')]
 */
class PhotoTaken implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $path,
        public string $mimeType = 'image/jpeg',
        public ?string $id = null
    ) {}
}
