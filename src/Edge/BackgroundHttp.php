<?php

namespace Native\Mobile\Edge;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Utils;

/**
 * The shared curl multi handle behind every background HTTP request a
 * screen registers with NativeComponent::background().
 *
 * The native runloop is single-threaded: a handler runs to completion
 * before the next event is read, so a request awaited inside mount() or a
 * press handler freezes input for the whole round trip. Requests built on
 * this handler — `Http::setHandler(BackgroundHttp::handler())->async()
 * ->get(...)` — go on the wire at once and progress on the runloop's idle
 * ticks; their promise callbacks run between events, on the runloop
 * thread, so a screen puts the result into state and re-renders from there.
 *
 * `select_timeout` is 0: a tick is a few milliseconds of curl_multi_exec,
 * never a blocking select — the runloop's own event wait is where time
 * passes.
 */
final class BackgroundHttp
{
    private static ?CurlMultiHandler $handler = null;

    public static function handler(): CurlMultiHandler
    {
        return self::$handler ??= new CurlMultiHandler(['select_timeout' => 0]);
    }

    /** Advance every transfer and run the promise callbacks that became due. */
    public static function tick(): void
    {
        self::handler()->tick();
        Utils::queue()->run();
    }
}