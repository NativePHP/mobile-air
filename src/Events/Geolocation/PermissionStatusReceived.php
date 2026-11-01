<?php

namespace Native\Mobile\Events\Geolocation;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class PermissionStatusReceived implements DispatchableFromNative
{
    use Dispatchable;

    public function __construct(
        public readonly string $location,
        public readonly string $coarseLocation,
        public readonly string $fineLocation,
        public readonly ?string $id = null
    ) {}
}
