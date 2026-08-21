<?php

namespace Native\Mobile\Support;

/**
 * Opt-in right-to-left (RTL) language detection shared by the Laravel
 * layer (`@nativeHead`, `@rtl`) and any server-rendered content.
 *
 * The device locale (not the Laravel locale) is still the authoritative
 * source for the *native* shell direction on iOS/Android; this class is
 * what keeps the language list consistent everywhere it is needed in PHP.
 */
class Rtl
{
    /**
     * Common RTL languages, keyed by their base ISO 639-1 code.
     *
     * This list is intentionally small and explicit (ar / he / fa / ur are
     * the guaranteed set); it is easy to extend with any additional RTL
     * language as required.
     */
    public const LANGUAGES = [
        'ar', // Arabic
        'he', // Hebrew
        'fa', // Persian / Farsi
        'ur', // Urdu
        'dv', // Divehi / Maldivian
        'ku', // Kurdish
        'ps', // Pashto
        'sd', // Sindhi
        'ug', // Uyghur
        'yi', // Yiddish
    ];

    /**
     * Return the base language for a locale, stripping any region /
     * script / variant tags. e.g. `ar-SA` -> `ar`, `he-IL` -> `he`,
     * `zh-Hans-CN` -> `zh`.
     */
    public static function baseLanguage(string $locale): string
    {
        $locale = trim($locale);

        if ($locale === '') {
            return '';
        }

        // Split on the first '-' or '_' (both are common separators in
        // BCP 47 tags and POSIX locales respectively).
        $parts = preg_split('/[-_]/', $locale, 2);

        return strtolower($parts[0] ?? '');
    }

    /**
     * Whether the given locale's base language is an RTL language.
     *
     * Variants such as `ar`, `ar-SA`, `ar-AE`, `fa-IR`, `he-IL` and
     * `ur-PK` all resolve through their base language, so `ar-SA` is RTL
     * while `en-SA` is LTR.
     */
    public static function isRtlLanguage(string $locale): bool
    {
        return in_array(self::baseLanguage($locale), self::LANGUAGES, true);
    }

    /**
     * Alias of {@see isRtlLanguage()} for readability at call sites that
     * work with a Laravel locale value.
     */
    public static function isRtlLocale(string $locale): bool
    {
        return self::isRtlLanguage($locale);
    }
}
