<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;

/**
 * The default source: asks nobody, so every region keeps its
 * placeholder. That is what an app without an Asgard client package
 * installed should look like (protocol §3), and it is not an error.
 */
class NullFragmentSource implements FragmentSource
{
    public function request(RegionRequest $request): void
    {
        //
    }
}
