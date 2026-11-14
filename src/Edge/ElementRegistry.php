<?php

namespace Native\Mobile\Edge;

class ElementRegistry
{
    protected static array $elements = [];

    public static function register(string $type, string $elementClass): void
    {
        static::$elements[$type] = $elementClass;

        if (is_a($elementClass, Element::class, true)) {
            NativeTagPrecompiler::registerElementEvents($elementClass::elementEvents());
        }
    }

    public static function has(string $type): bool
    {
        return isset(static::$elements[$type]);
    }

    public static function resolve(string $type): ?Element
    {
        if (! isset(static::$elements[$type])) {
            return null;
        }

        $class = static::$elements[$type];

        return new $class;
    }

    /** Whether the element registered for this type closes on navigation. */
    public static function closesOnNavigation(string $type): bool
    {
        $class = static::$elements[$type] ?? null;

        return $class !== null && is_a($class, Element::class, true) && $class::closesOnNavigation();
    }

    public static function all(): array
    {
        return static::$elements;
    }

    public static function reset(): void
    {
        static::$elements = [];
    }
}
