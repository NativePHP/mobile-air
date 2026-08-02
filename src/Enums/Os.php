<?php

namespace Native\Mobile\Enums;

use Native\Mobile\Platform;

/**
 * The mobile operating systems NativePHP targets.
 *
 * Backed by the same lowercase strings {@see Platform} has
 * always used on the wire (`'ios'` / `'android'`), so this is a typed
 * spelling of the existing values rather than a new vocabulary — anywhere a
 * string is accepted today, an enum case now is too.
 *
 * Named `Os` rather than `Platform` because `Native\Mobile\Platform` is the
 * class that resolves it, and two `Platform` symbols in adjacent namespaces
 * would be a permanent source of wrong imports.
 */
enum Os: string
{
    case Ios = 'ios';

    case Android = 'android';

    /**
     * Resolve a case-insensitive name, or null when it isn't one of ours.
     * Unlike `tryFrom()` this tolerates the casing people actually write
     * (`iOS`, `Android`).
     */
    public static function fromLabel(string $label): ?self
    {
        return self::tryFrom(strtolower(trim($label)));
    }

    public function isIos(): bool
    {
        return $this === self::Ios;
    }

    public function isAndroid(): bool
    {
        return $this === self::Android;
    }
}
