<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void flashlight()
 * @method static bool isAndroid()
 * @method static bool isIos()
 * @method static bool isMobile()
 * @method static void appSettings()
 */
class System extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\System::class;
    }
}
