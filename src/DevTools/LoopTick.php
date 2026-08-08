<?php

namespace Native\Mobile\DevTools;

use Illuminate\Container\Container;
use Native\Mobile\Contracts\EdgeTicker;
use Native\Mobile\Edge\NativeComponent;

/**
 * Inert relay between the EDGE loop's idle tick and an optional EdgeTicker
 * binding, mirroring CrashRelay: no binding means no-op and the loop keeps
 * its block-forever timeout; a throwing ticker is swallowed.
 */
class LoopTick
{
    protected static bool $ticking = false;

    public static function active(): bool
    {
        try {
            $container = Container::getInstance();

            return $container && $container->bound(EdgeTicker::class);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function run(NativeComponent $component): void
    {
        if (static::$ticking || ! static::active()) {
            return;
        }

        try {
            static::$ticking = true;

            Container::getInstance()->make(EdgeTicker::class)->tick($component);
        } catch (\Throwable) {
            // A broken ticker must never break the render loop.
        } finally {
            static::$ticking = false;
        }
    }
}
