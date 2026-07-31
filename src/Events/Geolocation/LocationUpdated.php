<?php

namespace Native\Mobile\Events\Geolocation;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A single position update from a location watch (Geolocation::watchPosition()).
 * Fired repeatedly — once per native location fix — until the watch is cleared.
 * `id` is the watch id, for correlating multiple concurrent watches.
 */
class LocationUpdated
{
    use Dispatchable;

    public function __construct(
        public bool $success,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $accuracy = null,
        public ?float $speed = null,
        public ?float $heading = null,
        public ?int $timestamp = null,
        public ?string $provider = null,
        public ?string $error = null,
        public ?string $id = null
    ) {}
}
