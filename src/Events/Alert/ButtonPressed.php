<?php

namespace Native\Mobile\Events\Alert;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class ButtonPressed implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $index,
        public string $label,
        public ?string $id = null
    ) {}
}
