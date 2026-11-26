<?php

namespace Tests\Fixtures\Edge;

/**
 * RouteTabsLayout as an older layout would have it: NativePHP draws the
 * bars itself (the custom-Column chrome path).
 */
class DrawnRouteTabsLayout extends RouteTabsLayout
{
    public function usesNativeChrome(): bool
    {
        return false;
    }
}
