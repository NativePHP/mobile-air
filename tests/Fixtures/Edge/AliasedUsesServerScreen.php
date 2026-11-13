<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Asgard\Concerns\UsesServer;

/** Brings in a UsesServer method under another name. */
class AliasedUsesServerScreen extends ServerRegionScreen
{
    use UsesServer {
        refreshServerRegions as public reloadRegions;
    }
}
