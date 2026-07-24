<?php

namespace Native\Mobile\Edge\Enums\Concerns;

/**
 * Shared normalization for the layout alignment enums.
 *
 * The native renderers read alignment as a single integer (the binary
 * wire value). These helpers let every alignment setter — and the Blade
 * attribute path — accept an enum case, that raw integer, or a
 * human-readable string label ("center", "space-between", …) and collapse
 * it to the one integer the renderers understand.
 */
trait ResolvesAlignmentValue
{
    /**
     * Resolve a case-insensitive string label (e.g. "center",
     * "space-between") to a case, or null when it isn't recognized.
     */
    abstract public static function fromLabel(string $label): ?self;

    /**
     * Normalize an enum case, integer, or string label to the integer wire
     * value. Accepts `mixed` so the Blade attribute path (whose values are
     * untyped) can call it directly. Returns null when the value can't be
     * resolved, so callers can skip it and leave the native default in place.
     */
    public static function parse(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value->value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        // Numeric strings (e.g. a Blade attribute like `alignItems="1"`).
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            return static::fromLabel($value)?->value;
        }

        return null;
    }
}
