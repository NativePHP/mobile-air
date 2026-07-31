<?php

namespace Native\Mobile\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Native\Mobile\Edge\Edge;
use Symfony\Component\HttpFoundation\Response;

class RenderEdgeComponents
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Send NativeUI data to native layer
        Edge::set();

        return $response;
    }
}
