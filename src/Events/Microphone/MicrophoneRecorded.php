<?php

namespace Native\Mobile\Events\Microphone;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * Microphone recording completed event
 *
 * This event is dispatched from native code (Kotlin/Swift) via JavaScript injection,
 * directly triggering Livewire listeners with #[On('native:Native\Mobile\Events\Microphone\MicrophoneRecorded')]
 */
class MicrophoneRecorded implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $path,
        public string $mimeType = 'audio/m4a',
        public ?string $id = null
    ) {}
}
