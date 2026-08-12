<?php

namespace Native\Mobile\Edge;

use InvalidArgumentException;
use Throwable;

/**
 * Package-extensible native-event handlers.
 *
 * Handlers are keyed by an ordinary namespaced event name and run before the
 * component's #[OnNative] listeners. A handler must explicitly return Handled
 * to claim the event; core ships none, so the default path remains unchanged.
 */
class NativeEventHandlers
{
    /** @var array<string, array<int, callable(array<string, mixed>, NativeComponent): NativeEventHandling>> */
    protected static array $handlers = [];

    /** @var array<int, string> */
    protected static array $index = [];

    protected static int $sequence = 0;

    public static function register(string $event, callable $handler): int
    {
        // Lowercase-only, because dispatch() matches the raw wire name with a
        // single isset() — a mixed-case registration would never fire.
        if (
            preg_match('/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9][a-z0-9._-]*)+\z/', $event) !== 1
            || str_starts_with($event, 'native:')
        ) {
            throw new InvalidArgumentException('Plugin native event names must be lowercase and use a non-core namespace such as vendor:command.');
        }

        $id = ++static::$sequence;
        static::$handlers[$event][$id] = $handler;
        static::$index[$id] = $event;

        return $id;
    }

    public static function unregister(int $id): void
    {
        $event = static::$index[$id] ?? null;

        if ($event === null) {
            return;
        }

        unset(static::$handlers[$event][$id], static::$index[$id]);

        if (static::$handlers[$event] === []) {
            unset(static::$handlers[$event]);
        }
    }

    public static function dispatch(string $event, mixed $payload, NativeComponent $component): bool
    {
        if (! isset(static::$handlers[$event])) {
            return false;
        }

        $handlers = static::$handlers[$event];
        $values = is_array($payload) ? $payload : ['value' => $payload];

        foreach ($handlers as $handler) {
            try {
                if ($handler($values, $component) === NativeEventHandling::Handled) {
                    return true;
                }
            } catch (Throwable $exception) {
                NativeRouter::debugLog('plugin native event handler failed: '.$exception->getMessage());
            }
        }

        return false;
    }

    public static function reset(): void
    {
        static::$handlers = [];
        static::$index = [];
        static::$sequence = 0;
    }
}
