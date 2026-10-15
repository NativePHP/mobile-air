<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null getId()
 * @method static string|null getInfo()
 * @method static string|null getBatteryInfo()
 * @method static bool vibrate()
 * @method static array toggleFlashlight()
 * @method static \Native\Mobile\ThermalState thermalState()
 * @method static void rememberThermalState(\Native\Mobile\ThermalState $state)
 * @method static void forgetThermalState()
 */
class Device extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\Device::class;
    }
}
