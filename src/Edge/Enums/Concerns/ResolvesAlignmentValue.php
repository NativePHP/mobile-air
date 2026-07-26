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
     *
     * This is the FRIENDLY vocabulary — it accepts aliases (`centre`,
     * `flex-start`, `spacebetween`) for the fluent PHP and Blade attribute
     * APIs. Tailwind utility classes deliberately do NOT go through here;
     * see {@see fromUtilityClass()}.
     */
    abstract public static function fromLabel(string $label): ?self;

    /**
     * Resolve the value part of a Tailwind utility class (`items-center` →
     * `center`) to a case, or null when Tailwind has no such class.
     *
     * Strict by design: the utility parser must track Tailwind's own
     * vocabulary, not our alias set. Otherwise `items-fill` and
     * `justify-spacebetween` become "valid" NativePHP classes that mean
     * nothing in Tailwind — forking the class language and leaving us
     * exposed if Tailwind later assigns those spellings a different meaning.
     * Defaults to the canonical label set; enums whose Tailwind spelling
     * differs (e.g. `justify-between`) override this.
     */
    public static function fromUtilityClass(string $value): ?self
    {
        return static::tryFromName($value);
    }

    /**
     * Case-insensitive lookup by canonical case name (`space-between` and
     * `spaceBetween` both match `SpaceBetween`). The shared basis for the
     * strict utility mapping.
     */
    protected static function tryFromName(string $value): ?self
    {
        $needle = strtolower(str_replace(['-', '_'], '', trim($value)));

        foreach (static::cases() as $case) {
            if (strtolower($case->name) === $needle) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Normalize an enum case, integer, or string label to the integer wire
     * value. Accepts `mixed` so the Blade attribute path (whose values are
     * untyped) can call it directly. Returns null when the value can't be
     * resolved, so callers can skip it and leave the native default in place.
     *
     * Integers are validated against the enum rather than passed through:
     * the native renderers switch on this value and fall back to their own
     * default for anything unknown, so letting `align-items="4"` reach the
     * wire just moves the failure somewhere harder to see.
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
            return static::tryFrom((int) $value)?->value;
        }

        // Numeric strings (e.g. a Blade attribute like `alignItems="1"`).
        if (is_numeric($value)) {
            return static::tryFrom((int) $value)?->value;
        }

        if (is_string($value)) {
            return static::fromLabel($value)?->value;
        }

        return null;
    }
}
