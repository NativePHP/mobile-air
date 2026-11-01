<?php

namespace Native\Mobile\Events\Scanner;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * Scanner cancelled event
 *
 * This event is dispatched when the scanner is closed without scanning a code
 * or when permission is denied.
 */
class ScannerCancelled implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public bool $cancelled = true,
        public ?string $reason = null,
        public ?string $id = null
    ) {}
}
