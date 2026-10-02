<?php

namespace Native\Mobile\Events\Camera;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Photo capture completed event
 *
 * This event is dispatched from native code (Kotlin/Swift) via JavaScript injection,
 * directly triggering Livewire listeners with #[On('native:Native\Mobile\Events\Camera\PhotoTaken')]
 */
class PhotoTaken
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $path,
        public string $mimeType = 'image/jpeg',
        public ?string $id = null,
        public ?string $takenAt = null,
        public ?float $latitude = null,
        public ?float $longitude = null
    ) {}
}
