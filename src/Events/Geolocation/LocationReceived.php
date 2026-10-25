<?php

namespace Native\Mobile\Events\Geolocation;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class LocationReceived implements DispatchableFromNative
{
    use Dispatchable;

    public function __construct(
        public bool $success,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $accuracy = null,
        public ?int $timestamp = null,
        public ?string $provider = null,
        public ?string $error = null,
        public ?string $id = null
    ) {}
}
