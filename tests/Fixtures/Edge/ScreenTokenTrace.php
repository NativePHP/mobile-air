<?php

namespace Tests\Fixtures\Edge;

/** Records what the token screens saw at each lifecycle point. */
class ScreenTokenTrace
{
    /** @var list<array{event: string, own: string, stack: list<string>}> */
    public static array $events = [];

    /** When true, the next detail mount replaces itself with a fresh mount of the same URI. */
    public static bool $replaceDetailOnce = false;

    public static function reset(): void
    {
        static::$events = [];
        static::$replaceDetailOnce = false;
    }

    /** @param  list<string>  $stack */
    public static function record(string $event, string $own, array $stack): void
    {
        static::$events[] = ['event' => $event, 'own' => $own, 'stack' => $stack];
    }

    /** @return array{event: string, own: string, stack: list<string>} */
    public static function first(string $event): array
    {
        foreach (static::$events as $entry) {
            if ($entry['event'] === $event) {
                return $entry;
            }
        }

        throw new \RuntimeException("No [{$event}] event recorded.");
    }

    /** @return list<array{event: string, own: string, stack: list<string>}> */
    public static function all(string $event): array
    {
        return array_values(array_filter(static::$events, fn (array $e): bool => $e['event'] === $event));
    }
}
