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

    /**
     * @return bool True when the ticker mutated state and the loop should
     *              re-render. A missing, re-entrant or throwing ticker
     *              reports false — an idle screen must never be forced to
     *              repaint by devtools.
     */
    public static function run(NativeComponent $component): bool
    {
        if (static::$ticking || ! static::active()) {
            return false;
        }

        try {
            static::$ticking = true;

            return Container::getInstance()->make(EdgeTicker::class)->tick($component);
        } catch (\Throwable) {
            // A broken ticker must never break the render loop.
            return false;
        } finally {
            static::$ticking = false;
        }
    }
}
