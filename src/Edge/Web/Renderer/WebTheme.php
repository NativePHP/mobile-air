<?php

namespace Native\Mobile\Edge\Web\Renderer;

/**
 * Bridges the mobile-ui Theme token store into CSS-consumable colors for
 * the web shell. Native wire colors are ARGB (#AARRGGBB); CSS wants
 * #RRGGBBAA, so 9-char values get reordered.
 */
class WebTheme
{
    /**
     * @return array{0: array<string,string>, 1: array<string,string>} [light, dark]
     */
    public static function palette(): array
    {
        $themeClass = 'Native\\Mobile\\UI\\Theme';

        if (class_exists($themeClass)) {
            $tokens = $themeClass::all();
            $light = static::cssColors($tokens['light'] ?? []);
            $dark = static::cssColors($tokens['dark'] ?? []);

            if (! empty($light)) {
                return [$light + static::defaults(), $dark + static::darkDefaults()];
            }
        }

        return [static::defaults(), static::darkDefaults()];
    }

    /** Semantic alias → font file token map from the mobile-ui Theme. */
    public static function fontAliases(): array
    {
        $themeClass = 'Native\\Mobile\\UI\\Theme';

        if (class_exists($themeClass)) {
            return $themeClass::all()['fonts'] ?? [];
        }

        return [];
    }

    /**
     * Resolve a `font="..."` token (alias or file basename) to a CSS
     * font-family name — the file basename, which is what the generated
     * @font-face rules declare. Null for none/System (browser default).
     */
    public static function resolveFont(?string $token): ?string
    {
        if ($token === null || $token === '' || strcasecmp($token, 'system') === 0) {
            return null;
        }

        $file = static::fontAliases()[$token] ?? $token;

        return strcasecmp($file, 'system') === 0 ? null : $file;
    }

    /** Font files available in the app's resources/fonts directory. */
    public static function fontFiles(): array
    {
        $dir = resource_path('fonts');

        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(
            scandir($dir),
            fn ($f) => preg_match('/\.(ttf|otf|woff2?)$/i', $f)
        ));
    }

    protected static function cssColors(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $key => $value) {
            if (! is_string($value) || ! str_starts_with($value, '#')) {
                continue;
            }

            $out[$key] = static::argbToCss($value);
        }

        return $out;
    }

    protected static function argbToCss(string $hex): string
    {
        // #AARRGGBB → #RRGGBBAA; #RRGGBB and #RGB pass through.
        if (strlen($hex) === 9) {
            return '#'.substr($hex, 3).substr($hex, 1, 2);
        }

        return $hex;
    }

    /** Material-ish fallback so screens are legible without mobile-ui. */
    protected static function defaults(): array
    {
        return [
            'primary' => '#6750a4',
            'on-primary' => '#ffffff',
            'primary-container' => '#eaddff',
            'on-primary-container' => '#21005d',
            'secondary' => '#625b71',
            'on-secondary' => '#ffffff',
            'secondary-container' => '#e8def8',
            'on-secondary-container' => '#1d192b',
            'surface' => '#fef7ff',
            'on-surface' => '#1d1b20',
            'surface-variant' => '#e7e0ec',
            'on-surface-variant' => '#49454f',
            'background' => '#fef7ff',
            'on-background' => '#1d1b20',
            'error' => '#b3261e',
            'on-error' => '#ffffff',
            'outline' => '#79747e',
            'outline-variant' => '#cac4d0',
        ];
    }

    protected static function darkDefaults(): array
    {
        return [
            'primary' => '#d0bcff',
            'on-primary' => '#381e72',
            'primary-container' => '#4f378b',
            'on-primary-container' => '#eaddff',
            'secondary' => '#ccc2dc',
            'on-secondary' => '#332d41',
            'secondary-container' => '#4a4458',
            'on-secondary-container' => '#e8def8',
            'surface' => '#141218',
            'on-surface' => '#e6e0e9',
            'surface-variant' => '#49454f',
            'on-surface-variant' => '#cac4d0',
            'background' => '#141218',
            'on-background' => '#e6e0e9',
            'error' => '#f2b8b5',
            'on-error' => '#601410',
            'outline' => '#938f99',
            'outline-variant' => '#49454f',
        ];
    }
}
