<?php

namespace Native\Mobile\Edge;

use Illuminate\Support\MessageBag;

/**
 * The error bag of the screen that is currently rendering.
 *
 * A component using {@see Concerns\ValidatesInput} binds its bag here for
 * the duration of each render. An input element can then show the message
 * for the field it is bound to while it hydrates:
 *
 *     $message = FieldErrors::for($attrs);
 *
 * Outside a render, or on a screen that does not validate, there is no bag
 * and every lookup returns null.
 */
final class FieldErrors
{
    private static ?MessageBag $current = null;

    public static function current(): ?MessageBag
    {
        return self::$current;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function using(MessageBag $bag, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $bag;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    /** The first message for a field, or null when it has none. */
    public static function first(string $field): ?string
    {
        $message = self::$current?->first($field);

        return $message === null || $message === '' ? null : $message;
    }

    /**
     * The first message for the field an element's Blade attributes bind:
     * an explicit `error-for`, otherwise the `native:model` property.
     *
     * @param  array<string, mixed>  $attrs
     */
    public static function for(array $attrs): ?string
    {
        $field = self::fieldFor($attrs);

        return $field === null ? null : self::first($field);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function fieldFor(array $attrs): ?string
    {
        foreach (['error-for', 'errorFor'] as $key) {
            if (isset($attrs[$key]) && is_string($attrs[$key]) && $attrs[$key] !== '') {
                return $attrs[$key];
            }
        }

        // `native:model="email"` precompiles to `_change="__syncProperty('email')"`.
        $change = $attrs['_change'] ?? null;

        if (is_string($change) && preg_match("/^__syncProperty\\('([^']+)'\\)$/", $change, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
