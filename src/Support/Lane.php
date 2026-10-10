<?php

namespace Native\Mobile\Support;

use Native\Mobile\Edge\NativeComponent;

/**
 * Tells whether this PHP interpreter is the main lane. A device
 * runs several in one app process, each with its own Laravel
 * application, which share no PHP values with each other.
 *
 * The main lane has the native screens. The others run
 * the queued jobs, the async tasks and the requests
 * of a web view that is part of a native screen.
 *
 * @internal
 */
class Lane
{
    /** How many pieces of background work this interpreter is inside of at the moment. */
    protected static int $background = 0;

    /** Whether this interpreter answers the requests of a web view in a native screen. */
    protected static bool $webView = false;

    /**
     * Run work that belongs to a background lane, a queued job or an
     * async task, and answer what it returned. The mark stays for
     * as long as the work takes, and goes even when it throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public static function background(callable $work): mixed
    {
        static::$background++;

        try {
            return $work();
        } finally {
            static::$background--;
        }
    }

    /**
     * Mark this interpreter as the one of a web view inside a native
     * screen. Native starts it for its web view and nothing else,
     * so the mark stays for as long as this interpreter lives.
     */
    public static function dedicateToWebView(): void
    {
        static::$webView = true;
    }

    /**
     * Tell whether this interpreter is the main lane. It is when it
     * has a live screen, whatever its marks say, and when it has
     * no mark at all, as nothing then says it is another one.
     */
    public static function isMain(): bool
    {
        return NativeComponent::active() !== null
            || (static::$background === 0 && ! static::$webView);
    }

    /** Take every mark back. Tests need it, as they play several lanes in one process. */
    public static function reset(): void
    {
        static::$background = 0;
        static::$webView = false;
    }
}
