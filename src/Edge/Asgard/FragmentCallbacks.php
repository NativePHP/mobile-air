<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\CallbackRegistry;

/**
 * The callback registry a region's fragment registers into
 * (docs/ASGARD-PROTOCOL.md §9.2, §10).
 *
 * A fragment callback slot is an object on the wire:
 *
 *     "on_press": {"call": "shuffle", "args": [3, "x"]}
 *     "on_press": {"call": "celebrate", "args": [], "target": "client"}
 *
 * parseSlot() checks one and gives back its canonical form. The
 * collector only speaks expression strings, so expressionFor() turns
 * that form into one, and this registry reads it back. What a fragment
 * id resolves to always carries a `target`, so a server callback can
 * never be run as a client one or the other way round. A screen's own
 * registry never produces a `target` at all: a placeholder or screen
 * callback cannot be mistaken for a fragment one.
 */
final class FragmentCallbacks extends CallbackRegistry
{
    public const TARGET_SERVER = 'server';

    public const TARGET_CLIENT = 'client';

    public const MAX_ARGS = 16;

    public const MAX_DEPTH = 4;

    private const PREFIX = 'asgard:';

    private const CALL_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,99}$/';

    /**
     * The canonical `['call', 'args', 'target']` form of a wire slot, or
     * null with `$reason` saying why it was refused.
     */
    public static function parseSlot(mixed $slot, ?string &$reason = null): ?array
    {
        $reason = null;

        if (is_string($slot)) {
            $reason = 'a callback is an object with a "call", not a string';

            return null;
        }

        if (! is_array($slot) || $slot === [] || array_is_list($slot)) {
            $reason = 'a callback must be an object with a "call"';

            return null;
        }

        if (($unknown = array_diff(array_keys($slot), ['call', 'args', 'target'])) !== []) {
            $reason = 'unknown keys '.implode(', ', array_map(fn ($key) => json_encode((string) $key), $unknown));

            return null;
        }

        $call = $slot['call'] ?? null;

        if (! is_string($call) || preg_match(self::CALL_PATTERN, $call) !== 1) {
            $reason = '"call" must be a method name';

            return null;
        }

        $args = $slot['args'] ?? [];

        if (! is_array($args) || ! array_is_list($args)) {
            $reason = '"args" must be a list';

            return null;
        }

        if (count($args) > self::MAX_ARGS) {
            $reason = '"args" has more than '.self::MAX_ARGS.' values';

            return null;
        }

        foreach ($args as $arg) {
            if (! self::jsonValue($arg, 0)) {
                $reason = '"args" holds something that is not a JSON value, or nests deeper than '.self::MAX_DEPTH;

                return null;
            }
        }

        $target = $slot['target'] ?? self::TARGET_SERVER;

        if ($target !== self::TARGET_SERVER && $target !== self::TARGET_CLIENT) {
            $reason = '"target" must be "server" or "client"';

            return null;
        }

        return ['call' => $call, 'args' => $args, 'target' => $target];
    }

    /** The expression string a canonical slot registers as. */
    public static function expressionFor(array $slot): string
    {
        return self::PREFIX.json_encode(
            [$slot['target'], $slot['call'], $slot['args']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * `['method', 'args', 'target']` for an expression expressionFor()
     * wrote. Anything else registered here resolves with a null target,
     * which dispatch drops.
     */
    protected function parseExpression(string $expression): array
    {
        $refused = ['method' => '', 'args' => [], 'target' => null];

        if (! str_starts_with($expression, self::PREFIX)) {
            return $refused;
        }

        $decoded = json_decode(substr($expression, strlen(self::PREFIX)), true);

        if (! is_array($decoded) || count($decoded) !== 3 || ! array_is_list($decoded)) {
            return $refused;
        }

        [$target, $call, $args] = $decoded;

        $slot = self::parseSlot(['call' => $call, 'args' => $args, 'target' => $target]);

        return $slot === null
            ? $refused
            : ['method' => $slot['call'], 'args' => $slot['args'], 'target' => $slot['target']];
    }

    /** null, a bool, an int, a finite float, a string, or a list/object of those. */
    private static function jsonValue(mixed $value, int $depth): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        if (! is_array($value) || $depth >= self::MAX_DEPTH) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::jsonValue($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
