<?php

namespace Native\Mobile\Edge;

class ElementRegistry
{
    protected static array $elements = [];

    public static function register(string $type, string $elementClass): void
    {
        static::$elements[$type] = $elementClass;
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

    public static function all(): array
    {
        return static::$elements;
    }

    public static function reset(): void
    {
        static::$elements = [];
    }
}
