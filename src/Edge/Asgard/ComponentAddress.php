<?php

namespace Native\Mobile\Edge\Asgard;

use Illuminate\Support\Str;
use Native\Mobile\Attributes\ServerAddress;
use ReflectionClass;

/**
 * Dot-notation component addressing (docs/ASGARD-PROTOCOL.md §4).
 *
 *     App\Native\Components\SalesSummary → app.native.components.sales_summary
 *
 * Backslashes become dots, each segment is snake-cased. A class marked
 * #[ServerAddress('home')] is called `home` instead. This is the only
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
    /** What an address looks like: dot notation, snake_case segments. */
    public const PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/D';

    /** @var array<class-string, string> */
    protected static array $named = [];

    public static function for(object|string $component): string
    {
        // A component that is a stand-in for many (a ServerScreen) says which.
        if ($component instanceof Contracts\AddressedComponent) {
            return $component->asgardAddress();
        }

        $class = ltrim(is_object($component) ? $component::class : $component, '\\');

        return static::$named[$class] ??= static::named($class) ?? static::derived($class);
    }

    /** The address the class names with #[ServerAddress], if it does. Attributes are not inherited. */
    protected static function named(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(ServerAddress::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->address;
    }

    protected static function derived(string $class): string
    {
        return implode('.', array_map(
            static fn (string $segment): string => Str::snake($segment),
            explode('\\', ltrim($class, '\\'))
        ));
    }
}
