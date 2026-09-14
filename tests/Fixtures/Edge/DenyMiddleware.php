<?php

namespace Tests\Fixtures\Edge;

use Closure;
use Illuminate\Http\Request;

/**
 * Stands in for `auth` — refuses the request and redirects, which is the
 * shape almost every real screen guard takes.
 */
class DenyMiddleware
{
    /** Every URI this middleware was asked to authorize, in order. */
    public static array $seen = [];

    public static string $redirectTo = '/login';

    public static function reset(): void
    {
        static::$seen = [];
        static::$redirectTo = '/login';
    }

    public function handle(Request $request, Closure $next)
    {
        static::$seen[] = $request->path();

        return redirect(static::$redirectTo);
    }
}
