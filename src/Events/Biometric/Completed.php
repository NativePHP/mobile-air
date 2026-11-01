<?php

namespace Native\Mobile\Events\Biometric;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class Completed implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public bool $success,
        public ?string $id = null
    ) {}
}
