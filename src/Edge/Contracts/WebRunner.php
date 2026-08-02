<?php

namespace Native\Mobile\Edge\Contracts;

use Illuminate\Http\Request;

/**
 * The seam between core routing and any web render target.
 *
 * Core never references a concrete web implementation: the Route::native
 * fallthrough and the update endpoint resolve this contract from the
 * container, so the whole web feature (renderer, protocol, bridge) can
 * live in a separate package whose service provider binds it. No binding
 * means no web rendering — a browser hit on a native route 404s.
 */
interface WebRunner
{
    /**
     * Render the full HTML page for a screen (a plain browser GET on a
     * Route::native URI with no native runtime present).
     *
     * @return mixed A response or renderable
     */
    public function screen(string $componentClass);

    /**
     * Handle a posted UI event against a sealed state snapshot and
     * return the update payload ({html, snapshot} or a navigation).
     *
     * @return mixed
     */
    public function update(Request $request);
}
