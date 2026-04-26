<?php

namespace Native\Mobile\Edge;

class TailwindParser
{
    private static array $cache = [];

    /**
     * Plugin-provided resolver for `bg-theme-*` / `text-theme-*` classes.
     * Takes a token name (e.g. "primary", "on-surface") and returns a hex
     * color string, or null if the token is unknown.
     *
     * Set by plugins (e.g. nativephp/native-ui's service provider) so this
     * parser remains dependency-free from specific plugin namespaces.
     *
     * @var callable(string): (?string)|null
     */
    private static $themeResolver = null;

    public static function setThemeResolver(?callable $resolver): void
    {
        static::$themeResolver = $resolver;
        // Bust cache — existing entries may reference stale theme values.
        static::$cache = [];
    }

    private const COLORS = [
        'slate' => [
            50 => '#F8FAFC', 100 => '#F1F5F9', 200 => '#E2E8F0', 300 => '#CBD5E1',
            400 => '#94A3B8', 500 => '#64748B', 600 => '#475569', 700 => '#334155',
            800 => '#1E293B', 900 => '#0F172A', 950 => '#020617',
        ],
        'gray' => [
            50 => '#F9FAFB', 100 => '#F3F4F6', 200 => '#E5E7EB', 300 => '#D1D5DB',
            400 => '#9CA3AF', 500 => '#6B7280', 600 => '#4B5563', 700 => '#374151',
            800 => '#1F2937', 900 => '#111827', 950 => '#030712',
        ],
        'zinc' => [
            50 => '#FAFAFA', 100 => '#F4F4F5', 200 => '#E4E4E7', 300 => '#D4D4D8',
            400 => '#A1A1AA', 500 => '#71717A', 600 => '#52525B', 700 => '#3F3F46',
            800 => '#27272A', 900 => '#18181B', 950 => '#09090B',
        ],
        'neutral' => [
            50 => '#FAFAFA', 100 => '#F5F5F5', 200 => '#E5E5E5', 300 => '#D4D4D4',
            400 => '#A3A3A3', 500 => '#737373', 600 => '#525252', 700 => '#404040',
            800 => '#262626', 900 => '#171717', 950 => '#0A0A0A',
        ],
        'stone' => [
            50 => '#FAFAF9', 100 => '#F5F5F4', 200 => '#E7E5E4', 300 => '#D6D3D1',
            400 => '#A8A29E', 500 => '#78716C', 600 => '#57534E', 700 => '#44403C',
            800 => '#292524', 900 => '#1C1917', 950 => '#0C0A09',
        ],
        'red' => [
            50 => '#FEF2F2', 100 => '#FEE2E2', 200 => '#FECACA', 300 => '#FCA5A5',
            400 => '#F87171', 500 => '#EF4444', 600 => '#DC2626', 700 => '#B91C1C',
            800 => '#991B1B', 900 => '#7F1D1D', 950 => '#450A0A',
        ],
        'orange' => [
            50 => '#FFF7ED', 100 => '#FFEDD5', 200 => '#FED7AA', 300 => '#FDBA74',
            400 => '#FB923C', 500 => '#F97316', 600 => '#EA580C', 700 => '#C2410C',
            800 => '#9A3412', 900 => '#7C2D12', 950 => '#431407',
        ],
        'amber' => [
            50 => '#FFFBEB', 100 => '#FEF3C7', 200 => '#FDE68A', 300 => '#FCD34D',
            400 => '#FBBF24', 500 => '#F59E0B', 600 => '#D97706', 700 => '#B45309',
            800 => '#92400E', 900 => '#78350F', 950 => '#451A03',
        ],
        'yellow' => [
            50 => '#FEFCE8', 100 => '#FEF9C3', 200 => '#FEF08A', 300 => '#FDE047',
            400 => '#FACC15', 500 => '#EAB308', 600 => '#CA8A04', 700 => '#A16207',
            800 => '#854D0E', 900 => '#713F12', 950 => '#422006',
        ],
        'lime' => [
            50 => '#F7FEE7', 100 => '#ECFCCB', 200 => '#D9F99D', 300 => '#BEF264',
            400 => '#A3E635', 500 => '#84CC16', 600 => '#65A30D', 700 => '#4D7C0F',
            800 => '#3F6212', 900 => '#365314', 950 => '#1A2E05',
        ],
        'green' => [
            50 => '#F0FDF4', 100 => '#DCFCE7', 200 => '#BBF7D0', 300 => '#86EFAC',
            400 => '#4ADE80', 500 => '#22C55E', 600 => '#16A34A', 700 => '#15803D',
            800 => '#166534', 900 => '#14532D', 950 => '#052E16',
        ],
        'emerald' => [
            50 => '#ECFDF5', 100 => '#D1FAE5', 200 => '#A7F3D0', 300 => '#6EE7B7',
            400 => '#34D399', 500 => '#10B981', 600 => '#059669', 700 => '#047857',
            800 => '#065F46', 900 => '#064E3B', 950 => '#022C22',
        ],
        'teal' => [
            50 => '#F0FDFA', 100 => '#CCFBF1', 200 => '#99F6E4', 300 => '#5EEAD4',
            400 => '#2DD4BF', 500 => '#14B8A6', 600 => '#0D9488', 700 => '#0F766E',
            800 => '#115E59', 900 => '#134E4A', 950 => '#042F2E',
        ],
        'cyan' => [
            50 => '#ECFEFF', 100 => '#CFFAFE', 200 => '#A5F3FC', 300 => '#67E8F9',
            400 => '#22D3EE', 500 => '#06B6D4', 600 => '#0891B2', 700 => '#0E7490',
            800 => '#155E75', 900 => '#164E63', 950 => '#083344',
        ],
        'sky' => [
            50 => '#F0F9FF', 100 => '#E0F2FE', 200 => '#BAE6FD', 300 => '#7DD3FC',
            400 => '#38BDF8', 500 => '#0EA5E9', 600 => '#0284C7', 700 => '#0369A1',
            800 => '#075985', 900 => '#0C4A6E', 950 => '#082F49',
        ],
        'blue' => [
            50 => '#EFF6FF', 100 => '#DBEAFE', 200 => '#BFDBFE', 300 => '#93C5FD',
            400 => '#60A5FA', 500 => '#3B82F6', 600 => '#2563EB', 700 => '#1D4ED8',
            800 => '#1E40AF', 900 => '#1E3A8A', 950 => '#172554',
        ],
        'indigo' => [
            50 => '#EEF2FF', 100 => '#E0E7FF', 200 => '#C7D2FE', 300 => '#A5B4FC',
            400 => '#818CF8', 500 => '#6366F1', 600 => '#4F46E5', 700 => '#4338CA',
            800 => '#3730A3', 900 => '#312E81', 950 => '#1E1B4B',
        ],
        'violet' => [
            50 => '#F5F3FF', 100 => '#EDE9FE', 200 => '#DDD6FE', 300 => '#C4B5FD',
            400 => '#A78BFA', 500 => '#8B5CF6', 600 => '#7C3AED', 700 => '#6D28D9',
            800 => '#5B21B6', 900 => '#4C1D95', 950 => '#2E1065',
        ],
        'purple' => [
            50 => '#FAF5FF', 100 => '#F3E8FF', 200 => '#E9D5FF', 300 => '#D8B4FE',
            400 => '#C084FC', 500 => '#A855F7', 600 => '#9333EA', 700 => '#7E22CE',
            800 => '#6B21A8', 900 => '#581C87', 950 => '#3B0764',
        ],
        'fuchsia' => [
            50 => '#FDF4FF', 100 => '#FAE8FF', 200 => '#F5D0FE', 300 => '#F0ABFC',
            400 => '#E879F9', 500 => '#D946EF', 600 => '#C026D3', 700 => '#A21CAF',
            800 => '#86198F', 900 => '#701A75', 950 => '#4A044E',
        ],
        'pink' => [
            50 => '#FDF2F8', 100 => '#FCE7F3', 200 => '#FBCFE8', 300 => '#F9A8D4',
            400 => '#F472B6', 500 => '#EC4899', 600 => '#DB2777', 700 => '#BE185D',
            800 => '#9D174D', 900 => '#831843', 950 => '#500724',
        ],
        'rose' => [
            50 => '#FFF1F2', 100 => '#FFE4E6', 200 => '#FECDD3', 300 => '#FDA4AF',
            400 => '#FB7185', 500 => '#F43F5E', 600 => '#E11D48', 700 => '#BE123C',
            800 => '#9F1239', 900 => '#881337', 950 => '#4C0519',
        ],
    ];

    private const SPACING = [
        '0' => 0, 'px' => 1, '0.5' => 2, '1' => 4, '1.5' => 6, '2' => 8, '2.5' => 10,
        '3' => 12, '3.5' => 14, '4' => 16, '5' => 20, '6' => 24, '7' => 28, '8' => 32,
        '9' => 36, '10' => 40, '11' => 44, '12' => 48, '14' => 56, '16' => 64, '20' => 80,
        '24' => 96, '28' => 112, '32' => 128, '36' => 144, '40' => 160, '44' => 176,
        '48' => 192, '52' => 208, '56' => 224, '60' => 240, '64' => 256, '72' => 288,
        '80' => 320, '96' => 384,
    ];

    private const FONT_SIZES = [
        'xs' => 12, 'sm' => 14, 'base' => 16, 'lg' => 18, 'xl' => 20,
        '2xl' => 24, '3xl' => 30, '4xl' => 36, '5xl' => 48, '6xl' => 60,
    ];

    private const FONT_WEIGHTS = [
        'thin' => 1, 'light' => 2, 'normal' => 3, 'medium' => 4,
        'semibold' => 5, 'bold' => 6, 'extrabold' => 7,
    ];

    private const BORDER_RADIUS = [
        'none' => 0, 'sm' => 2, 'md' => 6, 'lg' => 8,
        'xl' => 12, '2xl' => 16, '3xl' => 24, 'full' => 9999,
    ];

    private const SHADOW = [
        'sm' => 1, 'md' => 6, 'lg' => 8, 'xl' => 12, '2xl' => 16, 'none' => 0,
    ];

    private const WIDTH_FRACTIONS = [
        '1/2' => '50%', '1/3' => '33%', '2/3' => '67%',
        '1/4' => '25%', '2/4' => '50%', '3/4' => '75%',
        '1/5' => '20%', '2/5' => '40%', '3/5' => '60%', '4/5' => '80%',
        '1/6' => '17%', '5/6' => '83%',
    ];

    public static function parse(string $classString): array
    {
        if (isset(self::$cache[$classString])) {
            return self::$cache[$classString];
        }

        $result = [];
        $classes = preg_split('/\s+/', trim($classString), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($classes as $class) {
            $parsed = self::parseClass($class);
            if ($parsed !== null) {
                if (isset($parsed['dark']) && isset($result['dark'])) {
                    $result['dark'] = array_merge($result['dark'], $parsed['dark']);
                } else {
                    $result = array_merge($result, $parsed);
                }
            }
        }

        self::$cache[$classString] = $result;

        return $result;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function parseClass(string $class): ?array
    {
        // Dark mode variant: dark:class-name
        if (str_starts_with($class, 'dark:')) {
            $inner = self::parseClass(substr($class, 5));
            if ($inner === null) {
                return null;
            }

            return ['dark' => $inner];
        }

        // Arbitrary values: prefix-[value]
        if (str_contains($class, '[')) {
            if (preg_match('/^(.+?)-\[([^\]]+)\]$/', $class, $m)) {
                return self::parseArbitrary($m[1], $m[2]);
            }

            return null;
        }

        // Exact matches
        return match (true) {
            $class === 'flex-1' => ['flexGrow' => 1, 'flexShrink' => 1, 'flexBasis' => 0],
            $class === 'flex-grow' => ['flexGrow' => 1],
            $class === 'flex-grow-0' => ['flexGrow' => 0],
            $class === 'flex-shrink' => ['flexShrink' => 1],
            $class === 'flex-shrink-0' => ['flexShrink' => 0],
            $class === 'w-full' => ['fillWidth' => true],
            $class === 'h-full' => ['fillHeight' => true],
            $class === 'border' => ['borderWidth' => 1],
            $class === 'rounded' => ['borderRadius' => 4],
            $class === 'shadow' => ['elevation' => 3],
            $class === 'safe-area' => ['safeArea' => true],

            // Padding
            str_starts_with($class, 'px-') => self::parseSpacingAxis('padding', 'x', substr($class, 3)),
            str_starts_with($class, 'py-') => self::parseSpacingAxis('padding', 'y', substr($class, 3)),
            str_starts_with($class, 'pt-') => self::parseSpacingSide('paddingTop', substr($class, 3)),
            str_starts_with($class, 'pr-') => self::parseSpacingSide('paddingRight', substr($class, 3)),
            str_starts_with($class, 'pb-') => self::parseSpacingSide('paddingBottom', substr($class, 3)),
            str_starts_with($class, 'pl-') => self::parseSpacingSide('paddingLeft', substr($class, 3)),
            str_starts_with($class, 'p-') => self::parseSpacingUniform('padding', substr($class, 2)),

            // Margin
            str_starts_with($class, 'mx-') => self::parseSpacingAxis('margin', 'x', substr($class, 3)),
            str_starts_with($class, 'my-') => self::parseSpacingAxis('margin', 'y', substr($class, 3)),
            str_starts_with($class, 'mt-') => self::parseSpacingSide('marginTop', substr($class, 3)),
            str_starts_with($class, 'mr-') => self::parseSpacingSide('marginRight', substr($class, 3)),
            str_starts_with($class, 'mb-') => self::parseSpacingSide('marginBottom', substr($class, 3)),
            str_starts_with($class, 'ml-') => self::parseSpacingSide('marginLeft', substr($class, 3)),
            str_starts_with($class, 'm-') => self::parseSpacingUniform('margin', substr($class, 2)),

            // Gap, dimensions
            str_starts_with($class, 'gap-') => self::parseSpacingUniform('gap', substr($class, 4)),
            str_starts_with($class, 'w-') => self::parseWidth(substr($class, 2)),
            str_starts_with($class, 'h-') => self::parseHeight(substr($class, 2)),
            str_starts_with($class, 'left-') => self::parseSpacingUniform('left', substr($class, 5)),
            str_starts_with($class, 'top-') => self::parseSpacingUniform('top', substr($class, 4)),

            // Colors and text
            // Theme-aware tokens: `bg-theme-primary`, `text-theme-on-surface`, etc.
            // Checked BEFORE the generic bg-/text- branches so e.g. `bg-theme-*`
            // doesn't fall into the color-palette parser as `theme-primary`.
            str_starts_with($class, 'bg-theme-')   => self::parseThemeBg(substr($class, 9)),
            str_starts_with($class, 'text-theme-') => self::parseThemeText(substr($class, 11)),

            str_starts_with($class, 'bg-') => self::parseBgColor(substr($class, 3)),
            str_starts_with($class, 'text-') => self::parseText(substr($class, 5)),
            str_starts_with($class, 'font-') => self::parseFontWeight(substr($class, 5)),

            // Borders and visual
            str_starts_with($class, 'border-') => self::parseBorder(substr($class, 7)),
            str_starts_with($class, 'rounded-') => self::parseRounded(substr($class, 8)),
            str_starts_with($class, 'shadow-') => self::parseShadow(substr($class, 7)),
            str_starts_with($class, 'opacity-') => self::parseOpacity(substr($class, 8)),

            // Alignment
            str_starts_with($class, 'items-') => self::parseAlignItems(substr($class, 6)),
            str_starts_with($class, 'justify-') => self::parseJustifyContent(substr($class, 8)),
            str_starts_with($class, 'self-') => self::parseAlignSelf(substr($class, 5)),

            default => null,
        };
    }

    private static function parseSpacingUniform(string $key, string $value): ?array
    {
        if (isset(self::SPACING[$value])) {
            return [$key => self::SPACING[$value]];
        }

        return null;
    }

    private static function parseSpacingAxis(string $prop, string $axis, string $value): ?array
    {
        if (! isset(self::SPACING[$value])) {
            return null;
        }

        $v = self::SPACING[$value];

        if ($axis === 'x') {
            return ["{$prop}Left" => $v, "{$prop}Right" => $v];
        }

        return ["{$prop}Top" => $v, "{$prop}Bottom" => $v];
    }

    private static function parseSpacingSide(string $key, string $value): ?array
    {
        if (isset(self::SPACING[$value])) {
            return [$key => self::SPACING[$value]];
        }

        return null;
    }

    private static function parseWidth(string $value): ?array
    {
        if (isset(self::WIDTH_FRACTIONS[$value])) {
            return ['width' => self::WIDTH_FRACTIONS[$value]];
        }

        if (isset(self::SPACING[$value])) {
            return ['width' => self::SPACING[$value]];
        }

        return null;
    }

    private static function parseHeight(string $value): ?array
    {
        if (isset(self::SPACING[$value])) {
            return ['height' => self::SPACING[$value]];
        }

        return null;
    }

    private static function parseBgColor(string $value): ?array
    {
        if ($value === 'white') {
            return ['bg' => '#FFFFFF'];
        }
        if ($value === 'black') {
            return ['bg' => '#000000'];
        }
        if ($value === 'transparent') {
            return ['bg' => '#00000000'];
        }

        return self::resolveColor($value, 'bg');
    }

    /**
     * `bg-theme-<token>` — resolve a theme color via the registered resolver.
     *
     * Note: resolution uses the LIGHT-mode token. True dark-mode switching
     * happens at render time on the native side (via `<native:screen>` and
     * theme-aware component renderers). For fine-grained class-based tinting,
     * users accept that the light token is what ships to native.
     */
    private static function parseThemeBg(string $token): ?array
    {
        $color = self::resolveThemeToken($token);

        return $color !== null ? ['bg' => $color] : null;
    }

    private static function parseThemeText(string $token): ?array
    {
        $color = self::resolveThemeToken($token);

        return $color !== null ? ['color' => $color] : null;
    }

    private static function resolveThemeToken(string $token): ?string
    {
        if (static::$themeResolver === null) {
            return null;
        }
        $resolved = call_user_func(static::$themeResolver, $token);

        return is_string($resolved) ? $resolved : null;
    }

    private static function parseText(string $value): ?array
    {
        // Size keywords
        if (isset(self::FONT_SIZES[$value])) {
            return ['fontSize' => self::FONT_SIZES[$value]];
        }

        // Alignment
        return match ($value) {
            'left' => ['textAlign' => 0],
            'center' => ['textAlign' => 1],
            'right' => ['textAlign' => 2],
            'white' => ['color' => '#FFFFFF'],
            'black' => ['color' => '#000000'],
            'transparent' => ['color' => '#00000000'],
            default => self::resolveColor($value, 'color'),
        };
    }

    private static function parseFontWeight(string $value): ?array
    {
        if (isset(self::FONT_WEIGHTS[$value])) {
            return ['fontWeight' => self::FONT_WEIGHTS[$value]];
        }

        return null;
    }

    private static function parseBorder(string $value): ?array
    {
        // Width values
        $widths = ['0' => 0, '2' => 2, '4' => 4, '8' => 8];
        if (isset($widths[$value])) {
            return ['borderWidth' => $widths[$value]];
        }

        // Special colors
        if ($value === 'white') {
            return ['borderColor' => '#FFFFFF'];
        }
        if ($value === 'black') {
            return ['borderColor' => '#000000'];
        }
        if ($value === 'transparent') {
            return ['borderColor' => '#00000000'];
        }

        return self::resolveColor($value, 'borderColor');
    }

    private static function parseRounded(string $value): ?array
    {
        if (isset(self::BORDER_RADIUS[$value])) {
            return ['borderRadius' => self::BORDER_RADIUS[$value]];
        }

        return null;
    }

    private static function parseShadow(string $value): ?array
    {
        if (isset(self::SHADOW[$value])) {
            return ['elevation' => self::SHADOW[$value]];
        }

        return null;
    }

    private static function parseOpacity(string $value): ?array
    {
        if (is_numeric($value)) {
            return ['opacity' => (float) $value / 100];
        }

        return null;
    }

    private static function parseAlignItems(string $value): ?array
    {
        return match ($value) {
            'start' => ['alignItems' => 0],
            'center' => ['alignItems' => 1],
            'end' => ['alignItems' => 2],
            'stretch' => ['alignItems' => 3],
            default => null,
        };
    }

    private static function parseJustifyContent(string $value): ?array
    {
        return match ($value) {
            'start' => ['justifyContent' => 0],
            'center' => ['justifyContent' => 1],
            'end' => ['justifyContent' => 2],
            'between' => ['justifyContent' => 3],
            'around' => ['justifyContent' => 4],
            'evenly' => ['justifyContent' => 5],
            default => null,
        };
    }

    private static function parseAlignSelf(string $value): ?array
    {
        return match ($value) {
            'start' => ['alignSelf' => 0],
            'center' => ['alignSelf' => 1],
            'end' => ['alignSelf' => 2],
            'stretch' => ['alignSelf' => 3],
            default => null,
        };
    }

    private static function parseArbitrary(string $prefix, string $value): ?array
    {
        $isColor = str_starts_with($value, '#');

        return match ($prefix) {
            'p' => ['padding' => (float) $value],
            'px' => ['paddingLeft' => (float) $value, 'paddingRight' => (float) $value],
            'py' => ['paddingTop' => (float) $value, 'paddingBottom' => (float) $value],
            'pt' => ['paddingTop' => (float) $value],
            'pr' => ['paddingRight' => (float) $value],
            'pb' => ['paddingBottom' => (float) $value],
            'pl' => ['paddingLeft' => (float) $value],
            'm' => ['margin' => (float) $value],
            'mx' => ['marginLeft' => (float) $value, 'marginRight' => (float) $value],
            'my' => ['marginTop' => (float) $value, 'marginBottom' => (float) $value],
            'mt' => ['marginTop' => (float) $value],
            'mr' => ['marginRight' => (float) $value],
            'mb' => ['marginBottom' => (float) $value],
            'ml' => ['marginLeft' => (float) $value],
            'gap' => ['gap' => (float) $value],
            'w' => ['width' => (float) $value],
            'h' => ['height' => (float) $value],
            'bg' => $isColor ? ['bg' => self::normalizeHex($value)] : null,
            'text' => $isColor ? ['color' => self::normalizeHex($value)] : ['fontSize' => (float) $value],
            'rounded' => ['borderRadius' => (float) $value],
            'border' => $isColor ? ['borderColor' => self::normalizeHex($value)] : ['borderWidth' => (float) $value],
            'opacity' => ['opacity' => (float) $value],
            'left' => ['left' => (float) $value],
            'top' => ['top' => (float) $value],
            default => null,
        };
    }

    private static function resolveColor(string $value, string $key): ?array
    {
        $lastDash = strrpos($value, '-');
        if ($lastDash === false) {
            return null;
        }

        $family = substr($value, 0, $lastDash);
        $shade = substr($value, $lastDash + 1);

        if (! is_numeric($shade)) {
            return null;
        }

        $shade = (int) $shade;

        if (isset(self::COLORS[$family][$shade])) {
            return [$key => self::COLORS[$family][$shade]];
        }

        return null;
    }

    private static function normalizeHex(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . strtoupper($hex);
    }
}
