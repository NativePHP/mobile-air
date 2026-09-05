<?php

namespace Native\Mobile\Edge\Asgard;

use Illuminate\Support\Str;

/**
 * Dot-notation component addressing (docs/ASGARD-PROTOCOL.md §4).
 *
 *     App\Native\Components\SalesSummary → app.native.components.sales_summary
 *
 * Backslashes become dots, each segment is snake-cased. This is the only
 * name that crosses the wire for a component: a server resolves it
 * through a map built at boot and MUST NOT turn it back into a class
 * name, because the identifier arrives from a client and instantiating
 * it would be arbitrary object instantiation.
 *
 * The conversion is deliberately lossy in that direction — nothing here
 * reverses it — and it lives in one place so the region and
 * `#[Server]` workstreams cannot drift apart on what a component is
 * called.
 */
class ComponentAddress
{
    public static function for(object|string $component): string
    {
        $class = is_object($component) ? $component::class : $component;

        return implode('.', array_map(
            static fn (string $segment): string => Str::snake($segment),
            explode('\\', ltrim($class, '\\'))
        ));
    }
}
