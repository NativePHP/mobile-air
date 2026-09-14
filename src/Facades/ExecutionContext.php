<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array all()
 * @method static string state()
 * @method static string launch()
 * @method static bool isActive()
 * @method static bool isForeground()
 * @method static bool isBackground()
 * @method static bool launchedInBackground()
 * @method static bool hasBecomeActive()
 * @method static bool isHeadless()
 * @method static bool isProtectedDataAvailable()
 * @method static bool interactiveBootStarted()
 *
 * @see \Native\Mobile\ExecutionContext
 */
class ExecutionContext extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\ExecutionContext::class;
    }
}
