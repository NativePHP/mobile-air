<?php

namespace Native\Mobile\Events\Scanner;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * QR code or barcode scanned event
 *
 * This event is dispatched from native code (Kotlin/Swift) via JavaScript injection,
 * directly triggering Livewire listeners with #[OnNative(CodeScanned::class)]
 */
class CodeScanned implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $data,
        public string $format,
        public ?string $id = null
    ) {}
}
