<?php

namespace Native\Mobile\Edge;

/**
 * Registry for **child components** — NativeComponent subclasses that can be
 * mounted inside another component's Blade view with a `<native:*>` tag:
 *
 *     ComponentRegistry::components([
 *         'user-card' => \App\NativeComponents\UserCard::class,
 *     ]);
 *
 *     // in a screen's blade:
 *     <native:user-card :user="$user" key="user-{{ $user->id }}" />
 *
 * Resolution happens at RUNTIME (inside NativeElementCollector), not at
 * Blade compile time — so compiled views survive registry changes and a
 * tag registered after a view was compiled starts working without a
 * recompile. Registered element types always win over component names:
 * a tag that resolves through ElementRegistry is never treated as a
 * component mount.
 *
 * App components under app/NativeComponents (the `native:make` convention)
 * are auto-discovered by the service provider; explicit registrations made
 * before discovery are never overridden.
 */
class ComponentRegistry
{
    /** @var array<string, class-string<NativeComponent>> kebab tag name → class */
    protected static array $components = [];

    /**
     * Register a single child component under a kebab-case tag name.
     */
    public static function register(string $name, string $class): void
    {
        if (! is_subclass_of($class, NativeComponent::class)) {
            throw new \InvalidArgumentException(
                "Component [{$class}] registered for tag <native:{$name}> must extend ".NativeComponent::class.'.'
            );
        }

        static::$components[static::normalize($name)] = $class;
    }

    /**
     * Bulk registration — the idiomatic entry point for an app service
     * provider's boot():
     *
     *     ComponentRegistry::components(['user-card' => UserCard::class]);
     *
     * @param  array<string, class-string<NativeComponent>>  $components
     */
    public static function components(array $components): void
    {
        foreach ($components as $name => $class) {
            static::register($name, $class);
        }
    }

    public static function has(string $name): bool
    {
        return isset(static::$components[static::normalize($name)]);
    }

    /**
     * The component class registered for a tag name, or null. Accepts the
     * kebab tag (`user-card`) or its snake_case type form (`user_card`) —
     * the collector sees the latter after tagToType().
     *
     * @return class-string<NativeComponent>|null
     */
    public static function resolve(string $name): ?string
    {
        return static::$components[static::normalize($name)] ?? null;
    }

    /** @return array<string, class-string<NativeComponent>> */
    public static function all(): array
    {
        return static::$components;
    }

    public static function reset(): void
    {
        static::$components = [];
    }

    /** Tag names are stored kebab-case; accept snake_case lookups too. */
    protected static function normalize(string $name): string
    {
        return str_replace('_', '-', strtolower($name));
    }
}
