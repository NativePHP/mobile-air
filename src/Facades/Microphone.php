<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Native\Mobile\PendingMicrophone record()
 * @method static void stop()
 * @method static void pause()
 * @method static void resume()
 * @method static string getStatus()
 * @method static float level()
 * @method static string|null getRecording()
 */
class Microphone extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\Microphone::class;
    }
}
