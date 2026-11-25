<?php

namespace Native\Mobile\Edge;

use LogicException;
use Native\Mobile\Edge\Exceptions\ElementRegistrationException;

/**
 * Maps element types (`text`, `column`, `acme_widget`) to the Element class
 * that builds them.
 *
 * Every registration records an owner: `core`, or the package name of the
 * plugin that declared the type in its nativephp.json. A type belongs to
 * whoever registered it first. Registering it again under another owner, or
 * with another class, throws instead of quietly replacing it. Once the app
 * has booted the registry is locked and new types are refused too.
 *
 * Tests that need a fake element use swap() or fake(). A swapped type goes
 * back to its real owner the next time the app boots and registers it, and
 * this package's TestCase also resets the registry after every test.
 */
class ElementRegistry
{
    public const CORE = 'core';

    public const UNKNOWN = 'unknown';

    public const TEST = 'test';

    /** @var array<string, class-string> */
    protected static array $elements = [];

    /** @var array<string, string> */
    protected static array $owners = [];

    protected static bool $locked = false;

    /**
     * Register the Element class for a type.
     *
     * Registering the same type again with the same owner and class does
     * nothing, so an app that boots twice in one process is fine.
     *
     * @throws ElementRegistrationException when another owner or class already
     *                                      holds the type, or the registry is locked
     */
    public static function register(string $type, string $elementClass, string $owner = self::UNKNOWN): void
    {
        $elementClass = ltrim($elementClass, '\\');
        $current = static::$owners[$type] ?? null;

        // A test's swap() only holds a type until its real owner registers
        // it again, so the next app boot in a test suite doesn't trip on it.
        if ($current !== null && $current !== self::TEST) {
            if ($current === $owner && static::$elements[$type] === $elementClass) {
                return;
            }

            throw ElementRegistrationException::alreadyRegistered(
                $type,
                $current,
                static::$elements[$type],
                $owner,
                $elementClass,
            );
        }

        if ($current === null && static::$locked) {
            throw ElementRegistrationException::locked($type, $owner, $elementClass);
        }

        static::store($type, $elementClass, $owner);
    }

    public static function has(string $type): bool
    {
        return isset(static::$elements[$type]);
    }

    /**
     * The class registered for a type, or null.
     *
     * @return class-string|null
     */
    public static function get(string $type): ?string
    {
        return static::$elements[$type] ?? null;
    }

    /** Who registered a type: `core`, a plugin package name, or null. */
    public static function owner(string $type): ?string
    {
        return static::$owners[$type] ?? null;
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

    /**
     * Refuse any new type from now on. Called once the app has booted, after
     * core and every plugin manifest have registered their elements.
     */
    public static function lock(): void
    {
        static::$locked = true;
    }

    public static function isLocked(): bool
    {
        return static::$locked;
    }

    /**
     * Test helper: use $elementClass for $type, whoever owns the type and
     * even when the registry is locked. It lasts until the registry is reset
     * or the type's real owner registers it again on the next boot.
     */
    public static function swap(string $type, string $elementClass): void
    {
        static::ensureTesting('swap');

        static::store($type, ltrim($elementClass, '\\'), self::TEST);
    }

    /**
     * Test helper: replace the whole registry with exactly these elements.
     *
     * @param  array<string, class-string>  $elements
     */
    public static function fake(array $elements = []): void
    {
        static::reset();

        foreach ($elements as $type => $class) {
            static::swap($type, $class);
        }
    }

    /**
     * Test helper: forget every registration and unlock. The next app boot
     * registers core and plugin elements again.
     */
    public static function reset(): void
    {
        static::ensureTesting('reset');

        static::$elements = [];
        static::$owners = [];
        static::$locked = false;
    }

    protected static function store(string $type, string $elementClass, string $owner): void
    {
        static::$elements[$type] = $elementClass;
        static::$owners[$type] = $owner;

        if (is_a($elementClass, Element::class, true)) {
            NativeTagPrecompiler::registerElementEvents($elementClass::elementEvents());
        }
    }

    /**
     * The helpers that bypass ownership only work under a test runner, so a
     * plugin can't use them to take over a type at runtime. PHPUnit's
     * TestCase is loaded whenever PHPUnit or Pest runs tests, and never in a
     * running app. (Checking the app environment instead would break tests
     * that switch it to `production`.)
     */
    protected static function ensureTesting(string $method): void
    {
        if (class_exists('PHPUnit\\Framework\\TestCase', false)) {
            return;
        }

        throw new LogicException(
            "ElementRegistry::{$method}() is only available in tests. ".
            'Declare elements in a plugin\'s nativephp.json `components` instead.'
        );
    }
}
