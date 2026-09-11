<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void flashlight()
 * @method static bool isAndroid()
 * @method static bool isIos()
 * @method static bool isMobile()
 * @method static void appSettings()
 * @method static string appearance()
 * @method static bool isDarkMode()
 * @method static bool isLightMode()
 * @method static void rememberAppearance(string $mode)
 * @method static string orientation()
 * @method static bool isPortrait()
 * @method static bool isLandscape()
 * @method static void rememberOrientation(string $orientation)
 */
class System extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\System::class;
    }
}
