<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null getId()
 * @method static string|null getInfo()
 * @method static string|null getBatteryInfo()
 * @method static \Native\Mobile\Data\Localization|null localization()
 * @method static bool vibrate()
 * @method static array toggleFlashlight()
 */
class Device extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\Device::class;
    }
}
