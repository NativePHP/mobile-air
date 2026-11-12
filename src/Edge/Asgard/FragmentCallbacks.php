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
 * A slot can also move the app (§12, "Navigation"):
 *
 *     "on_press": {"navigate": "/promos/42", "replace": true, "transition": "fade"}
 *     "on_press": {"back": true}
 *
 * That canonical form has the `navigate` target, a `call` of navigate,
 * replace or back, and args of [uri, transition]. Only the press slots
 * may navigate, and the URI must be an app path. Whether a route matches
 * it is checked when the tap lands, against the routes the app has then.
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

    public const TARGET_NAVIGATE = 'navigate';

    /** The device's Transition values a navigation may name. */
    public const TRANSITIONS = [
        'slide_from_right', 'slide_from_left', 'slide_from_bottom', 'fade',
        'fade_from_bottom', 'scale_from_center', 'parallax_push', 'morph', 'none',
    ];

    /** Slots a navigation may sit in. A value change or a submit never moves the app. */
    public const NAVIGABLE_SLOTS = ['on_press', 'on_long_press', 'on_double_tap'];

    public const MAX_URI_LENGTH = 2048;

    public const MAX_ARGS = 16;

    public const MAX_DEPTH = 4;

    private const PREFIX = 'asgard:';

    private const CALL_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,99}$/';

    /**
     * The canonical `['call', 'args', 'target']` form of a wire slot, or
     * null with `$reason` saying why it was refused.
     */
    public static function parseSlot(mixed $slot, ?string &$reason = null, string $slotName = 'on_press'): ?array
    {
        $reason = null;

        if (is_array($slot) && (array_key_exists('navigate', $slot) || array_key_exists('back', $slot))) {
            if (! in_array($slotName, self::NAVIGABLE_SLOTS, true)) {
                $reason = "{$slotName} cannot navigate";

                return null;
            }

            return self::parseNavigation($slot, $reason);
        }

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

    /**
     * The canonical form of a navigation slot, or null with `$reason`.
     */
    private static function parseNavigation(array $slot, ?string &$reason): ?array
    {
        $back = array_key_exists('back', $slot);
        $allowed = $back ? ['back', 'transition'] : ['navigate', 'replace', 'transition'];

        if (($unknown = array_diff(array_keys($slot), $allowed)) !== []) {
            $reason = 'unknown keys '.implode(', ', array_map(fn ($key) => json_encode((string) $key), $unknown));

            return null;
        }

        $transition = $slot['transition'] ?? null;

        if ($transition !== null && ! in_array($transition, self::TRANSITIONS, true)) {
            $reason = '"transition" must be one of '.implode(', ', self::TRANSITIONS);

            return null;
        }

        if ($back) {
            if ($slot['back'] !== true) {
                $reason = '"back" must be true';

                return null;
            }

            return ['call' => 'back', 'args' => [null, $transition], 'target' => self::TARGET_NAVIGATE];
        }

        if (! self::isAppPath($slot['navigate'])) {
            $reason = '"navigate" must be an app path starting with one "/", not a web URL';

            return null;
        }

        $replace = $slot['replace'] ?? false;

        if (! is_bool($replace)) {
            $reason = '"replace" must be a boolean';

            return null;
        }

        return ['call' => $replace ? 'replace' : 'navigate', 'args' => [$slot['navigate'], $transition], 'target' => self::TARGET_NAVIGATE];
    }

    /**
     * An app path: one leading slash, no scheme or host, no backslash, no
     * whitespace or control characters. A web URL is never one.
     */
    public static function isAppPath(mixed $uri): bool
    {
        return is_string($uri)
            && $uri !== ''
            && strlen($uri) <= self::MAX_URI_LENGTH
            && $uri[0] === '/'
            && ! str_starts_with($uri, '//')
            && ! str_contains($uri, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $uri) !== 1;
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

        $slot = self::canonical($target, $call, $args);

        return $slot === null
            ? $refused
            : ['method' => $slot['call'], 'args' => $slot['args'], 'target' => $slot['target']];
    }

    /**
     * Check a slot already in canonical form (what parseSlot() returns) and
     * give it back, or null when it is not one. The fragment store and
     * expressions only ever hold this form.
     */
    public static function canonical(mixed $target, mixed $call, mixed $args): ?array
    {
        if ($target === self::TARGET_NAVIGATE) {
            $valid = is_array($args) && array_is_list($args) && count($args) === 2
                && ($args[1] === null || in_array($args[1], self::TRANSITIONS, true))
                && ($call === 'back'
                    ? $args[0] === null
                    : in_array($call, ['navigate', 'replace'], true) && self::isAppPath($args[0]));

            return $valid ? ['call' => $call, 'args' => $args, 'target' => $target] : null;
        }

        return self::parseSlot(['call' => $call, 'args' => $args, 'target' => $target]);
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
