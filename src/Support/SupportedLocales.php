<?php

namespace Native\Mobile\Support;

/**
 * The languages an app declares it supports.
 *
 * Two different questions get asked about locales at compile time, and only
 * one of them is answered here:
 *
 *  - "which languages does this app support?" — a scope decision, owned by
 *    the app developer, that drives the Android per-app language picker, the
 *    iOS per-app language setting, and the languages both stores advertise;
 *  - "what is the French text for NSCameraUsageDescription?" — content, which
 *    a plugin is welcome to ship.
 *
 * `config('nativephp.supported_locales')` answers the first and is the only
 * input to it: a plugin contributes content, never scope. Declaring nothing
 * means supporting one language, the app's own `config('app.locale')`, which
 * always leads the list so the user can switch back to it explicitly.
 *
 * Resolution is identical on both platforms, so it lives here rather than
 * being duplicated in each compiler.
 */
class SupportedLocales
{
    /**
     * A language subtag, then any script/region/variant subtags.
     *
     * These values are written straight into an XML attribute and a plist, so
     * what a typo produces is a broken build at best — validate rather than
     * interpolate whatever the config happens to hold.
     */
    private const PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/';

    /** @var list<string> */
    private array $locales;

    /** @var list<string> */
    private array $invalid = [];

    private string $base;

    /**
     * @param  array<mixed>  $declared  Raw `supported_locales` config value
     * @param  string  $base  The app's own language, normally `config('app.locale')`
     */
    public function __construct(array $declared, string $base)
    {
        $this->base = self::normalize($base) ?: 'en';

        $locales = [$this->base];

        foreach ($declared as $locale) {
            if (! is_string($locale)) {
                $this->invalid[] = is_scalar($locale) ? (string) $locale : gettype($locale);

                continue;
            }

            $locale = self::normalize($locale);

            if ($locale === '') {
                continue;
            }

            if (! preg_match(self::PATTERN, $locale)) {
                $this->invalid[] = $locale;

                continue;
            }

            $locales[] = $locale;
        }

        $this->locales = array_values(array_reduce(
            $locales,
            function (array $carry, string $locale): array {
                // Case-insensitive de-dupe, first spelling wins: `pt-br` and
                // `pt-BR` are the same language, and the base locale must not
                // appear twice just because it was also declared.
                foreach ($carry as $seen) {
                    if (strcasecmp($seen, $locale) === 0) {
                        return $carry;
                    }
                }

                $carry[] = $locale;

                return $carry;
            },
            []
        ));
    }

    /**
     * Read the declarations from config. Kept separate from the constructor
     * so the resolution itself can be tested without booting a container.
     */
    public static function fromConfig(): self
    {
        return new self(
            (array) config('nativephp.supported_locales', []),
            (string) config('app.locale', 'en')
        );
    }

    /**
     * Apple and Android both want BCP 47 identifiers (`nl`, `zh-Hans`,
     * `pt-BR`); Laravel and PHP write `nl_NL`. Normalize to the hyphen form.
     */
    public static function normalize(string $locale): string
    {
        return str_replace('_', '-', trim($locale));
    }

    /**
     * Every supported locale, base language first.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->locales;
    }

    /** The app's own language — what unlocalized strings are written in. */
    public function base(): string
    {
        return $this->base;
    }

    /**
     * Whether there is actually a language to choose between. A picker
     * offering a single entry is worse than no picker at all.
     */
    public function offersChoice(): bool
    {
        return count($this->locales) > 1;
    }

    /** Whether a locale declared elsewhere (by a plugin, say) is in scope. */
    public function allows(string $locale): bool
    {
        $locale = self::normalize($locale);

        foreach ($this->locales as $supported) {
            if (strcasecmp($supported, $locale) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Config entries that were dropped, for the compiler to warn about.
     *
     * @return list<string>
     */
    public function invalid(): array
    {
        return $this->invalid;
    }

    /**
     * Ready-to-print warnings about the declarations themselves.
     *
     * Lives here rather than in each compiler so both platforms say the same
     * thing about the same config.
     *
     * @return list<string>
     */
    public function warnings(string $langPath): array
    {
        $warnings = [];

        foreach ($this->invalid as $invalid) {
            $warnings[] = "Ignoring invalid locale '{$invalid}' in nativephp.supported_locales";
        }

        $undeclared = $this->undeclared($langPath);

        if (! empty($undeclared)) {
            $warnings[] = 'Translations exist for '.implode(', ', $undeclared)
                .' but nativephp.supported_locales does not list them, so the app will not offer them';
        }

        return $warnings;
    }

    /**
     * Locales the app has Laravel translations for but never declared.
     *
     * The declarations stay explicit — scanning `lang/` to decide what ships
     * would offer a half-translated language the moment someone starts one,
     * and Laravel publishes `lang/en/validation.php` into apps that are not
     * localized at all. But having the translations and forgetting the
     * declaration is a real mistake worth naming at build time.
     *
     * @return list<string>
     */
    public function undeclared(string $langPath): array
    {
        if (! is_dir($langPath)) {
            return [];
        }

        $candidates = [];

        foreach ((array) scandir($langPath) as $entry) {
            if ($entry === false || str_starts_with($entry, '.') || $entry === 'vendor') {
                continue;
            }

            $path = $langPath.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($path)) {
                $candidates[] = $entry;

                continue;
            }

            if (str_ends_with($entry, '.json')) {
                $candidates[] = substr($entry, 0, -5);
            }
        }

        $missing = [];

        foreach ($candidates as $candidate) {
            $candidate = self::normalize($candidate);

            if (! preg_match(self::PATTERN, $candidate) || $this->allows($candidate)) {
                continue;
            }

            $missing[$candidate] = true;
        }

        return array_keys($missing);
    }
}
