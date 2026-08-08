<?php

namespace Native\Mobile\Contracts;

use Native\Mobile\Edge\NativeComponent;

/**
 * Gets a turn on the EDGE render loop's idle tick. Core never binds an
 * implementation; when nothing is bound the loop blocks on events exactly
 * as before. The nativephp/devtools plugin binds one in debug builds to
 * service agent commands (tree dumps, synthetic events) between frames.
 */
interface EdgeTicker
{
    public function tick(NativeComponent $component): void;
}
