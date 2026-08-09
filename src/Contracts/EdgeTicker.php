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
    /**
     * @return bool True when the tick mutated component state and the
     *              screen must repaint. False for read-only work — the
     *              loop then goes back to waiting without re-rendering,
     *              which is what keeps an idle screen at zero renders.
     */
    public function tick(NativeComponent $component): bool;
}
