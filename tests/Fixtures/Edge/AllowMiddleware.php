<?php

namespace Tests\Fixtures\Edge;

use Closure;
use Illuminate\Http\Request;

/** Passes the request straight through, recording that it ran. */
class AllowMiddleware
{
    /** Every URI this middleware was asked to authorize, in order. */
    public static array $seen = [];

    public static function reset(): void
    {
        static::$seen = [];
    }

    public function handle(Request $request, Closure $next)
    {
        static::$seen[] = $request->path();

        return $next($request);
    }
}
