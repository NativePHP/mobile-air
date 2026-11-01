<?php

namespace Native\Mobile\Events\Microphone;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class MicrophoneCancelled implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public bool $cancelled = true,
        public ?string $id = null
    ) {}
}
