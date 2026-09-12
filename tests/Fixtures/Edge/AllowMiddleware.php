<?php

namespace Tests\Fixtures\Edge;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passes the request straight through, recording that it ran.
 *
 * The `: Response` return type is load-bearing, not decoration — it is what
 * Laravel's own `make:middleware` stub generates, and it means the guard's
 * pipeline destination MUST return a real Response rather than null.
 */
class AllowMiddleware
{
    /** Every URI this middleware was asked to authorize, in order. */
    public static array $seen = [];

    public static function reset(): void
    {
        static::$seen = [];
    }

    public function handle(Request $request, Closure $next): Response
    {
        static::$seen[] = $request->path();

        return $next($request);
    }
}
