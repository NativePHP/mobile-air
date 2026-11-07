<?php

namespace Native\Mobile\Edge\Asgard\Contracts;

use Native\Mobile\Edge\Asgard\RegionRequest;

/**
 * Where a region's fragment comes from (docs/ASGARD-PROTOCOL.md §3, §8).
 *
 * The client asks once a region has painted its placeholder, and again
 * when a component calls refreshServerRegions(). request() is fire and
 * forget: it must return quickly, because it runs on the UI thread at
 * the end of a frame. An implementation does its work elsewhere (an
 * AsyncTask, typically) and delivers later with
 * `ServerRegion::deliver($request, $tree)`, or from an AsyncTask
 * `->finished()` callback with `$this->receiveFragment($request, $tree)`.
 *
 * Never delivering is allowed. The region keeps its placeholder, which
 * is the intended offline state rather than an error.
 */
interface FragmentSource
{
    public function request(RegionRequest $request): void;
}
