<?php

namespace Native\Mobile\Support;

/**
 * The size and idle timeout of the async task pool, as written into
 * bundle_meta.json for the native executors to read at launch.
 *
 * @internal
 */
class AsyncPoolConfig
{
    /** The C lane on iOS has room for this many contexts and no more. */
    public const HARD_MAX_SLOTS = 4;

    public const DEFAULT_MAX_SLOTS = 2;

    public const DEFAULT_IDLE_TIMEOUT = 60;

    public static function maxSlots(): int
    {
        $slots = config('nativephp.async.max_slots') ?? static::DEFAULT_MAX_SLOTS;

        return max(1, min(static::HARD_MAX_SLOTS, (int) $slots));
    }

    /** Seconds an idle interpreter is kept before it shuts down. 0 keeps it for good. */
    public static function idleTimeout(): int
    {
        return max(0, (int) (config('nativephp.async.idle_timeout') ?? static::DEFAULT_IDLE_TIMEOUT));
    }
}
