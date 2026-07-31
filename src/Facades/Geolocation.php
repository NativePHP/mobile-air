<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;
use Native\Mobile\PendingGeolocation;

/**
 * @method static PendingGeolocation getCurrentPosition(bool $fineAccuracy = false)
 * @method static PendingGeolocation checkPermissions()
 * @method static PendingGeolocation requestPermissions()
 *
 * @see \Native\Mobile\Geolocation
 */
class Geolocation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Native\Mobile\Geolocation::class;
    }
}
