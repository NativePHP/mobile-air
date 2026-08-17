<?php

namespace Native\Mobile\Edge;

use Illuminate\Support\Facades\Log;
use Native\Mobile\Edge\Enums\AlignItems;
use Native\Mobile\Edge\Enums\AlignSelf;
use Native\Mobile\Edge\Enums\JustifyContent;
use Native\Mobile\Edge\Enums\TextAlign;
use Native\Mobile\Platform;

class TailwindParser
{
    private static array $cache = [];

    /** @var array<string, list<string>> */
    private static array $unsupportedCache = [];

    /** @var list<array{view: string, enabled: bool, classes: array<string, true>}> */
    private static array $diagnosticScopes = [];

    /** @var array<string, array<string, true>> */
    private static array $reportedUnsupportedByView = [];

    /**
     * Plugin-provided resolver for `bg-theme-*` / `text-theme-*` classes —
     * resolves a token name (e.g. "primary", "on-surface") to its LIGHT-mode
     * hex color, or null if unknown.
     *
     * @var callable(string): (?string)|null
     */
    private static $themeResolver = null;

    /**
     * Companion resolver for the same tokens against the DARK token set.
     * When set, theme-class parsing emits a `dark` companion node so the
     * collector / native side can pick the dark hex at render time based on
     * system colorScheme (existing `dark_bg_color` / `dark_color` /
     * `dark_border_color` machinery in NodeStyleModifier).
     *
     * @var callable(string): (?string)|null
     */
    private static $themeDarkResolver = null;

    public static function setThemeResolver(?callable $resolver): void
    {
        static::$themeResolver = $resolver;
        static::clearCache();
    }

    public static function setThemeDarkResolver(?callable $resolver): void
    {
        static::$themeDarkResolver = $resolver;
        static::clearCache();
    }

    /**
     * Test seam — force the parser's view of the current platform.
     * Delegates to [Platform::set] and clears the parsed-class cache so
     * platform-variant classes are re-evaluated.
     */
    public static function setPlatform(?string $platform): void
    {
        Platform::set($platform);
        static::clearCache();
    }

    private static function currentPlatform(): ?string
    {
        return Platform::current();
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

    /**
     * Which corners each `rounded-<side>-*` suffix touches. Sides expand to
     * their two corners, exactly as Tailwind's longhand does.
     *
     * Only the PHYSICAL spellings are here. Tailwind's logical variants
     * (`rounded-s-*`, `rounded-ee-*`, …) resolve against the writing
     * direction, and neither renderer flips corners for RTL yet — accepting
     * them would silently render LTR geometry in an RTL layout, so they stay
     * unparsed and land in the dropped-class diagnostics instead.
     *
     * @var array<string, list<string>>
     */
    private const BORDER_RADIUS_CORNERS = [
        'tl' => ['borderRadiusTopLeft'],
        'tr' => ['borderRadiusTopRight'],
        'br' => ['borderRadiusBottomRight'],
        'bl' => ['borderRadiusBottomLeft'],
        't' => ['borderRadiusTopLeft', 'borderRadiusTopRight'],
        'r' => ['borderRadiusTopRight', 'borderRadiusBottomRight'],
        'b' => ['borderRadiusBottomRight', 'borderRadiusBottomLeft'],
        'l' => ['borderRadiusTopLeft', 'borderRadiusBottomLeft'],
    ];

    private const SHADOW = [
        'sm' => 1, 'md' => 6, 'lg' => 8, 'xl' => 12, '2xl' => 16, 'none' => 0,
    ];

    /**
     * Tailwind's container scale, used by `max-w-*` (and, in v4, `min-w-*`).
     * Values are the rem sizes converted at the 16px root Tailwind assumes.
     * Most are far wider than a phone, but they're what authors type and a
     * constraint that never binds is still better than a dropped class.
     */
    private const CONTAINER_SIZES = [
        '3xs' => 256, '2xs' => 288, 'xs' => 320, 'sm' => 384, 'md' => 448,
        'lg' => 512, 'xl' => 576, '2xl' => 672, '3xl' => 768, '4xl' => 896,
        '5xl' => 1024, '6xl' => 1152, '7xl' => 1280,
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
            self::recordUnsupported(self::$unsupportedCache[$classString] ?? []);

            return self::$cache[$classString];
        }

        $result = [];
        $unsupported = [];
        $classes = preg_split('/\s+/', trim($classString), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($classes as $class) {
            $parsed = self::parseClass($class);
            if ($parsed === null) {
                if (! self::isInactivePlatformVariant($class)) {
                    $unsupported[$class] = true;
                }

                continue;
            }
            // Merge dark companion separately so a class that contributes BOTH
            // a light key AND a dark key (e.g. `bg-theme-surface`) doesn't
            // drop one side when another dark-bearing class is already merged.
            if (isset($parsed['dark'])) {
                $result['dark'] = isset($result['dark'])
                    ? array_merge($result['dark'], $parsed['dark'])
                    : $parsed['dark'];
                unset($parsed['dark']);
            }
            // Same reason for gradients: direction and each colour stop arrive
            // as separate classes contributing separate keys to one `gradient`
            // array. A flat merge would let `to-transparent` clobber the
            // direction and `from-` stop that came before it.
            if (isset($parsed['gradient'])) {
                $result['gradient'] = isset($result['gradient'])
                    ? array_merge($result['gradient'], $parsed['gradient'])
                    : $parsed['gradient'];
                unset($parsed['gradient']);
            }
            $result = array_merge($result, $parsed);
        }

        self::$cache[$classString] = $result;
        self::$unsupportedCache[$classString] = array_keys($unsupported);
        self::recordUnsupported(self::$unsupportedCache[$classString]);

        return $result;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
        self::$unsupportedCache = [];
    }

    /**
     * Group unsupported utility diagnostics under the Blade view currently
     * being rendered. Scopes nest for child components and partials, so each
     * warning points back to the view that authored the dropped classes.
     */
    public static function beginViewDiagnostics(string $view): void
    {
        self::$diagnosticScopes[] = [
            'view' => $view,
            'enabled' => (bool) config('app.debug', false),
            'classes' => [],
        ];
    }

    public static function endViewDiagnostics(): void
    {
        $scope = array_pop(self::$diagnosticScopes);

        if ($scope === null || ! $scope['enabled'] || $scope['classes'] === []) {
            return;
        }

        $reported = self::$reportedUnsupportedByView[$scope['view']] ?? [];
        $classes = array_values(array_filter(
            array_keys($scope['classes']),
            fn (string $class): bool => ! isset($reported[$class])
        ));

        if ($classes === []) {
            return;
        }

        foreach ($classes as $class) {
            self::$reportedUnsupportedByView[$scope['view']][$class] = true;
        }

        Log::warning('NativePHP EDGE dropped unsupported Tailwind classes.', [
            'view' => $scope['view'],
            'classes' => $classes,
        ]);
    }

    /** @param  list<string>  $classes */
    private static function recordUnsupported(array $classes): void
    {
        $scopeIndex = array_key_last(self::$diagnosticScopes);

        if ($scopeIndex === null || ! self::$diagnosticScopes[$scopeIndex]['enabled']) {
            return;
        }

        foreach ($classes as $class) {
            self::$diagnosticScopes[$scopeIndex]['classes'][$class] = true;
        }
    }

    /**
     * Platform variants that target another platform are intentional no-ops,
     * not unsupported utilities. A malformed class containing both platform
     * targets is still reported when one target matches the current platform.
     */
    private static function isInactivePlatformVariant(string $class): bool
    {
        $targets = array_values(array_intersect(explode(':', $class), ['ios', 'android']));

        return $targets !== [] && ! in_array(self::currentPlatform(), $targets, true);
    }

    /**
     * Resolve a standalone color VALUE (not a utility class) to wire-format
     * hex. This is the shared authoring-layer color grammar — theme config
     * tokens, element color props, and arbitrary-value classes all accept
     * the same inputs:
     *
     *  - Palette names, with optional opacity: `red-300`, `orange-800/50`
     *  - Special names: `white`, `black`, `transparent`
     *  - CSS hex, with optional opacity: `#F00`, `#F00C`, `#B91C1C`,
     *    `#8B5CF680`, `#8B5CF6/50`
     *
     * Authored 8-digit hex is CSS order (#RRGGBBAA); the return value is
     * `#RRGGBB` or `#AARRGGBB` — the ARGB byte order both native
     * ColorParsers (Swift and Kotlin) read off the wire.
     *
     * Returns null when the value isn't recognized as a color, so callers
     * can pass unknown strings through untouched.
     */
    public static function resolveColorValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Optional trailing `/N` (or `/[N]`) opacity modifier.
        $alphaHex = null;
        $slashPos = strrpos($value, '/');
        if ($slashPos !== false) {
            $alphaHex = self::opacityToAlphaHex(substr($value, $slashPos + 1));
            if ($alphaHex === null) {
                return null;
            }
            $value = substr($value, 0, $slashPos);
        }

        $hex = match ($value) {
            'white' => '#FFFFFF',
            'black' => '#000000',
            'transparent' => '#00000000',
            default => str_starts_with($value, '#')
                ? self::normalizeHex($value)
                : self::paletteHex($value),
        };

        if ($hex === null) {
            return null;
        }

        if ($alphaHex !== null) {
            $rgb = strlen($hex) === 9 ? substr($hex, 3) : substr($hex, 1);
            $hex = '#'.$alphaHex.$rgb;
        }

        return $hex;
    }

    private static function parseClass(string $class): ?array
    {
        // Pre-strip trailing `/N` opacity modifier (Tailwind v3+ syntax).
        // Only color-bearing prefixes are eligible — sizing classes like
        // `w-1/2` use `/` for fractions, not alpha.
        [$class, $alphaHex] = self::extractColorAlpha($class);

        $result = self::parseClassImpl($class);

        if ($alphaHex !== null && is_array($result)) {
            $result = self::applyAlphaToColorResult($result, $alphaHex);
        }

        return $result;
    }

    private static function parseClassImpl(string $class): ?array
    {
        // Platform variants: ios:class-name / android:class-name
        // The class is applied only on the matching platform; on the other
        // platform it drops silently (so e.g. `android:bg-theme-primary` is
        // a no-op on iOS, leaving any unprefixed bg class to win).
        // Composes with `dark:` either way: `ios:dark:foo` and
        // `dark:ios:foo` both work via the recursive parse.
        if (str_starts_with($class, 'ios:')) {
            return self::currentPlatform() === 'ios'
                ? self::parseClass(substr($class, 4))
                : null;
        }
        if (str_starts_with($class, 'android:')) {
            return self::currentPlatform() === 'android'
                ? self::parseClass(substr($class, 8))
                : null;
        }

        // Dark mode variant: dark:class-name
        if (str_starts_with($class, 'dark:')) {
            $inner = self::parseClass(substr($class, 5));
            if ($inner === null) {
                return null;
            }

            return ['dark' => $inner];
        }

        // Negative utilities: `-mt-4`, `-right-8`, `-left-[12]`. Parsed by
        // stripping the sign, running the positive form, then negating the
        // result. Placed AFTER the variant prefixes so `ios:-right-8` works,
        // and BEFORE arbitrary values so `-left-[12]` does too.
        //
        // Only inset and margin utilities may go negative — Tailwind itself
        // has no negative padding/gap/size, and silently accepting one here
        // would emit nonsense geometry instead of an obvious no-op. The
        // negative form of anything else parses to null (class ignored).
        if (str_starts_with($class, '-')) {
            return self::negateSpacing(self::parseClassImpl(substr($class, 1)));
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
            // Direction — lets any container (notably pressable, which
            // defaults to column) flip axis via class, matching web flexbox.
            $class === 'flex-row', $class === 'flex-row-reverse' => ['flexDirection' => 1],
            $class === 'flex-col', $class === 'flex-col-reverse' => ['flexDirection' => 0],
            $class === 'flex-1' => ['flexGrow' => 1, 'flexShrink' => 1, 'flexBasis' => 0],
            $class === 'flex-grow', $class === 'grow' => ['flexGrow' => 1],
            $class === 'flex-grow-0', $class === 'grow-0' => ['flexGrow' => 0],
            $class === 'flex-shrink', $class === 'shrink' => ['flexShrink' => 1],
            $class === 'flex-shrink-0', $class === 'shrink-0' => ['flexShrink' => 0],
            $class === 'flex-wrap' => ['flexWrap' => 1],
            $class === 'flex-nowrap' => ['flexWrap' => 0],
            $class === 'flex-wrap-reverse' => ['flexWrap' => 2],
            $class === 'w-full' => ['fillWidth' => true],
            $class === 'h-full' => ['fillHeight' => true],
            $class === 'border' => ['borderWidth' => 1],
            $class === 'rounded' => ['borderRadius' => 4],
            $class === 'shadow' => ['elevation' => 3],
            $class === 'safe-area' => ['safeArea' => true],
            $class === 'safe-area-top' => ['safeAreaTop' => true],
            $class === 'safe-area-bottom' => ['safeAreaBottom' => true],

            // Liquid Glass material — iOS 26+ Liquid Glass with graceful
            // fallback (`.regularMaterial` on iOS 18-25; tonal surface on
            // Compose). Composes with `rounded-*` / `px-*` / etc. so any
            // padded element can be a glass surface.
            //
            // Modifiers chain after a colon, in any order:
            //
            //   glass                              regular glass
            //   glass:prominent                    button-only — `.buttonStyle(.glassProminent)`
            //   glass:interactive                  adds touch-highlight feedback
            //   glass:clear                        `.glassEffect(.clear)` — fully translucent,
            //                                        no tint backdrop (older iOS: `.ultraThinMaterial`).
            //                                        Ignored on buttons (no `.buttonStyle(.glassClear)`).
            //   glass:clear:interactive            etc. — modifiers compose freely
            //
            // Encoded as a single int with bitflags into the existing
            // `glass` prop slot — no new wire keys:
            //
            //   bit 0 (1) — enabled
            //   bit 1 (2) — prominent
            //   bit 2 (4) — interactive
            //   bit 3 (8) — clear
            //
            // Unknown segments (typos like `glass:thicc`) are silently
            // ignored; the base `glass` flag still applies.
            $class === 'glass' || str_starts_with($class, 'glass:') => self::parseGlassClass($class),

            // Position
            $class === 'absolute' => ['positionType' => 1],
            $class === 'relative' => ['positionType' => 0],

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

            // Gap, dimensions.
            //
            // The min-/max- constraints MUST precede the bare `w-`/`h-`
            // branches only for readability — they don't actually collide
            // (`max-w-4` doesn't start with `w-`) — but grouping them keeps
            // the sizing rules together.
            str_starts_with($class, 'gap-') => self::parseSpacingUniform('gap', substr($class, 4)),
            str_starts_with($class, 'min-w-') => self::parseSizeConstraint('minWidth', substr($class, 6)),
            str_starts_with($class, 'max-w-') => self::parseSizeConstraint('maxWidth', substr($class, 6)),
            str_starts_with($class, 'min-h-') => self::parseSizeConstraint('minHeight', substr($class, 6)),
            str_starts_with($class, 'max-h-') => self::parseSizeConstraint('maxHeight', substr($class, 6)),
            str_starts_with($class, 'w-') => self::parseWidth(substr($class, 2)),
            str_starts_with($class, 'h-') => self::parseHeight(substr($class, 2)),
            // Inset shorthands. `inset-x-`/`inset-y-` MUST precede the bare
            // `inset-` branch, which would otherwise match them first and try
            // to parse "x-0" as a spacing value.
            str_starts_with($class, 'inset-x-') => self::explicitInsets(self::parseInset(substr($class, 8), ['positionLeft', 'positionRight'])),
            str_starts_with($class, 'inset-y-') => self::explicitInsets(self::parseInset(substr($class, 8), ['positionTop', 'positionBottom'])),
            str_starts_with($class, 'inset-') => self::explicitInsets(self::parseInset(substr($class, 6), ['positionTop', 'positionRight', 'positionBottom', 'positionLeft'])),

            str_starts_with($class, 'left-') => self::explicitInsets(self::parseSpacingUniform('positionLeft', substr($class, 5))),
            str_starts_with($class, 'top-') => self::explicitInsets(self::parseSpacingUniform('positionTop', substr($class, 4))),
            str_starts_with($class, 'right-') => self::explicitInsets(self::parseSpacingUniform('positionRight', substr($class, 6))),
            str_starts_with($class, 'bottom-') => self::explicitInsets(self::parseSpacingUniform('positionBottom', substr($class, 7))),

            // Colors and text
            // Theme-aware tokens: `bg-theme-primary`, `text-theme-on-surface`, etc.
            // Checked BEFORE the generic bg-/text- branches so e.g. `bg-theme-*`
            // doesn't fall into the color-palette parser as `theme-primary`.
            str_starts_with($class, 'bg-theme-') => self::parseThemeBg(substr($class, 9)),
            str_starts_with($class, 'text-theme-') => self::parseThemeText(substr($class, 11)),
            str_starts_with($class, 'border-theme-') => self::parseThemeBorder(substr($class, 13)),

            // Linear gradients. These MUST precede the generic `bg-` branch,
            // which would otherwise swallow `bg-gradient-to-t` and try to
            // resolve "gradient-to-t" as a color. `bg-linear-to-*` is the
            // Tailwind v4 spelling; `bg-gradient-to-*` the v3 one.
            str_starts_with($class, 'bg-gradient-to-') => self::parseGradientDirection(substr($class, 15)),
            str_starts_with($class, 'bg-linear-to-') => self::parseGradientDirection(substr($class, 13)),
            str_starts_with($class, 'from-') => self::parseGradientStop('from', substr($class, 5)),
            str_starts_with($class, 'via-') => self::parseGradientStop('via', substr($class, 4)),
            str_starts_with($class, 'to-') => self::parseGradientStop('to', substr($class, 3)),

            str_starts_with($class, 'bg-') => self::parseBgColor(substr($class, 3)),
            str_starts_with($class, 'text-') => self::parseText(substr($class, 5)),

            // Font family. Exact matches MUST precede the `font-` weight branch.
            // Sent as int: 0 = sans (default), 1 = serif, 2 = mono.
            $class === 'font-sans' => ['fontFamily' => 0],
            $class === 'font-serif' => ['fontFamily' => 1],
            $class === 'font-mono' => ['fontFamily' => 2],

            str_starts_with($class, 'font-') => self::parseFontWeight(substr($class, 5)),

            // Font style (italic). Sent as int: 1 = italic, 0 = normal.
            $class === 'italic' => ['fontStyle' => 1],
            $class === 'not-italic' => ['fontStyle' => 0],

            // Text decoration. Independent flags so `underline line-through`
            // combines. `no-underline` clears both lines.
            $class === 'underline' => ['underline' => 1],
            $class === 'line-through' => ['lineThrough' => 1],
            $class === 'no-underline' => ['underline' => 0, 'lineThrough' => 0],

            // Text transform. Sent as int: 0 none, 1 upper, 2 lower, 3 capitalize.
            $class === 'uppercase' => ['textTransform' => 1],
            $class === 'lowercase' => ['textTransform' => 2],
            $class === 'capitalize' => ['textTransform' => 3],
            $class === 'normal-case' => ['textTransform' => 0],

            // Text selection (opt-in). Mirrors CSS `user-select`: `select-text`
            // makes this node's subtree long-press-selectable (native Copy
            // menu); `select-none` opts a subtree back out inside a selectable
            // ancestor. Inherited/container-scoped on both platforms.
            $class === 'select-text' => ['selectable' => 1],
            $class === 'select-none' => ['selectable' => 0],

            // Whitespace (CSS white-space) for text content. Consumed by the
            // PHP capture layer before the text prop is serialized, so it
            // never rides the wire. `nowrap` and `pre-wrap` need native
            // line-wrap props and stay unparsed for now.
            $class === 'whitespace-normal' => ['whitespace' => 'normal'],
            $class === 'whitespace-pre-line' => ['whitespace' => 'pre-line'],
            $class === 'whitespace-pre' => ['whitespace' => 'pre'],

            // Letter spacing (tracking), in em (relative to font size).
            $class === 'tracking-tighter' => ['letterSpacing' => -0.05],
            $class === 'tracking-tight' => ['letterSpacing' => -0.025],
            $class === 'tracking-normal' => ['letterSpacing' => 0],
            $class === 'tracking-wide' => ['letterSpacing' => 0.025],
            $class === 'tracking-wider' => ['letterSpacing' => 0.05],
            $class === 'tracking-widest' => ['letterSpacing' => 0.1],

            // Line height (leading), as a unitless multiplier of font size.
            // Arbitrary forms — `leading-[1.4]` (multiplier) and
            // `leading-[24px]` (absolute) — are handled in parseArbitrary.
            $class === 'leading-none' => ['lineHeight' => 1.0],
            $class === 'leading-tight' => ['lineHeight' => 1.25],
            $class === 'leading-snug' => ['lineHeight' => 1.375],
            $class === 'leading-normal' => ['lineHeight' => 1.5],
            $class === 'leading-relaxed' => ['lineHeight' => 1.625],
            $class === 'leading-loose' => ['lineHeight' => 2.0],

            // Borders and visual
            str_starts_with($class, 'border-') => self::parseBorder(substr($class, 7)),
            str_starts_with($class, 'rounded-') => self::parseRounded(substr($class, 8)),
            str_starts_with($class, 'shadow-') => self::parseShadow(substr($class, 7)),
            str_starts_with($class, 'opacity-') => self::parseOpacity(substr($class, 8)),

            // Alignment
            str_starts_with($class, 'items-') => self::parseAlignItems(substr($class, 6)),
            str_starts_with($class, 'justify-') => self::parseJustifyContent(substr($class, 8)),
            str_starts_with($class, 'self-') => self::parseAlignSelf(substr($class, 5)),

            // Object fit (images) — mirrors CSS `object-fit`, mapped to the
            // `fit` prop the image renderer reads (0 none, 1 contain/fit,
            // 2 cover/crop, 3 fill/stretch).
            $class === 'object-none' => ['fit' => 0],
            $class === 'object-contain' => ['fit' => 1],
            $class === 'object-cover' => ['fit' => 2],
            $class === 'object-fill' => ['fit' => 3],
            $class === 'object-scale-down' => ['fit' => 1],

            // Aspect ratio — sets the `aspect_ratio` flex layout field
            // (applied natively via AspectRatioModifier on iOS/Android).
            $class === 'aspect-square' => ['aspectRatio' => 1.0],
            $class === 'aspect-video' => ['aspectRatio' => 16 / 9],

            default => null,
        };
    }

    /**
     * Pull a trailing `/N` (or `/[N]`) opacity modifier off a color class.
     *
     * Returns `[strippedClass, alphaHexByte]` where the alpha byte is a
     * two-char uppercase hex (e.g. `4D` for 30%). When the class is not a
     * color-bearing class, or has no slash, alpha is null and the class is
     * returned unchanged.
     *
     * Only `bg-`, `text-`, `border-` prefixes are eligible. Sizing classes
     * like `w-1/2` legitimately use `/` for fraction values, so we leave
     * them alone.
     *
     * Bracketed alpha values (`bg-red-500/[27]`) are supported for parity
     * with Tailwind v3+ arbitrary-value syntax. Out-of-range values clamp
     * to 0..100 silently.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function extractColorAlpha(string $class): array
    {
        $isColorPrefix = str_starts_with($class, 'bg-')
            || str_starts_with($class, 'text-')
            || str_starts_with($class, 'border-');

        if (! $isColorPrefix) {
            return [$class, null];
        }

        $slashPos = strrpos($class, '/');
        if ($slashPos === false) {
            return [$class, null];
        }

        $alphaHex = self::opacityToAlphaHex(substr($class, $slashPos + 1));

        if ($alphaHex === null) {
            // Slash followed by non-numeric — not an alpha modifier (e.g.
            // `border-l-2` doesn't have one but `bg-foo/bar` would land here
            // and we'd safely fall through). Leave the class intact.
            return [$class, null];
        }

        return [substr($class, 0, $slashPos), $alphaHex];
    }

    /**
     * Convert an opacity modifier tail (`50` or `[50]`, 0–100, clamped) to
     * a two-char uppercase alpha hex byte. Null when non-numeric.
     */
    private static function opacityToAlphaHex(string $tail): ?string
    {
        // Bracketed arbitrary alpha: bg-red-500/[27]
        if (preg_match('/^\[(\d+)\]$/', $tail, $m)) {
            $tail = $m[1];
        }

        if (! ctype_digit($tail)) {
            return null;
        }

        $opacity = max(0, min(100, (int) $tail));
        $alphaByte = (int) round($opacity * 255 / 100);

        return strtoupper(str_pad(dechex($alphaByte), 2, '0', STR_PAD_LEFT));
    }

    /**
     * Inject an alpha byte into every hex color value in a parsed-class
     * result array. Recurses into the `dark` companion so theme tokens get
     * both their light and dark hexes alpha-modified.
     *
     * Non-hex values (numbers, bools, font sizes, etc.) are left untouched
     * so it's safe to call this unconditionally on any parseClass result.
     *
     * Handles both 6-char (#RRGGBB) and 8-char (#AARRGGBB) inputs — for
     * the 8-char case the existing alpha is overwritten.
     */
    private static function applyAlphaToColorResult(array $result, string $alphaHex): array
    {
        foreach ($result as $key => $val) {
            if ($key === 'dark' && is_array($val)) {
                $result[$key] = self::applyAlphaToColorResult($val, $alphaHex);

                continue;
            }
            if (! is_string($val)) {
                continue;
            }
            if (preg_match('/^#([0-9A-Fa-f]{6})$/', $val, $m)) {
                $result[$key] = '#'.$alphaHex.strtoupper($m[1]);
            } elseif (preg_match('/^#[0-9A-Fa-f]{2}([0-9A-Fa-f]{6})$/', $val, $m)) {
                $result[$key] = '#'.$alphaHex.strtoupper($m[1]);
            }
        }

        return $result;
    }

    private static function parseSpacingUniform(string $key, string $value): ?array
    {
        if (isset(self::SPACING[$value])) {
            return [$key => self::SPACING[$value]];
        }

        return null;
    }

    /**
     * Keys a leading `-` may legally flip. Mirrors Tailwind: margins and
     * insets take negatives, padding / gap / sizing never do.
     */
    private const NEGATABLE = [
        'margin', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
        'positionTop', 'positionRight', 'positionBottom', 'positionLeft',
    ];

    /**
     * Flip the sign of a parsed spacing result, or reject it.
     *
     * Returns null when $parsed is null (the positive form didn't parse) or
     * carries ANY key outside [NEGATABLE] — a negative form of a
     * non-negatable utility is a typo, and dropping the class is safer than
     * emitting geometry the author didn't ask for.
     *
     * @param  array<string, mixed>|null  $parsed
     * @return array<string, float>|null
     */
    private static function negateSpacing(?array $parsed): ?array
    {
        if ($parsed === null || $parsed === []) {
            return null;
        }

        $negated = [];

        foreach ($parsed as $key => $value) {
            if (! in_array($key, self::NEGATABLE, true) || ! is_numeric($value)) {
                return null;
            }

            $negated[$key] = -(float) $value;
        }

        return $negated;
    }

    /**
     * Parse a `glass` token (with optional colon-chained modifiers) into a
     * bitflag stored in the `glass` prop. See the inline doc above for the
     * supported modifier set.
     *
     * @return array{glass: int}
     */
    private static function parseGlassClass(string $class): array
    {
        $flags = 1; // bit 0 = enabled

        $parts = explode(':', $class);
        foreach (array_slice($parts, 1) as $mod) {
            if ($mod === 'prominent') {
                $flags |= 2;
            } elseif ($mod === 'interactive') {
                $flags |= 4;
            } elseif ($mod === 'clear') {
                $flags |= 8;
            }
            // Unknown modifiers are ignored — keeps typos from breaking the
            // base glass effect.
        }

        return ['glass' => $flags];
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

    /**
     * `max-w-*` / `min-w-*` / `max-h-*` / `min-h-*`.
     *
     * Accepts the spacing scale (`max-w-64`), the container scale that only
     * max-width has in Tailwind (`max-w-sm`), and `none` (an explicit "no
     * constraint", which is what the wire's 0 already means).
     *
     * The `full` / `screen*` / `min` / `max` / `fit` keywords are deliberately
     * NOT accepted: the packed node carries min/max as bare floats with no
     * companion size mode, so there is nowhere to put "100% of the parent".
     * Leaving them unparsed lands them in the dropped-class diagnostics
     * instead of silently doing nothing.
     */
    private static function parseSizeConstraint(string $key, string $value): ?array
    {
        if ($value === 'none') {
            return [$key => 0];
        }

        if (isset(self::SPACING[$value])) {
            return [$key => self::SPACING[$value]];
        }

        if (($key === 'maxWidth' || $key === 'minWidth') && isset(self::CONTAINER_SIZES[$value])) {
            return [$key => self::CONTAINER_SIZES[$value]];
        }

        return null;
    }

    /**
     * Parse an aspect-ratio token — either `W/H` (e.g. `16/9`) or a plain
     * decimal (e.g. `1.5`). A plain `(float)` cast can't be used for the
     * `W/H` form because PHP would stop at the slash (`"16/9"` casts to
     * `16.0`). Returns 0.0 for malformed input; the native AspectRatio
     * modifiers ignore any non-positive ratio.
     */
    private static function parseRatio(string $value): float
    {
        if (str_contains($value, '/')) {
            [$w, $h] = array_pad(explode('/', $value, 2), 2, '1');
            $h = (float) $h;

            return $h != 0.0 ? (float) $w / $h : 0.0;
        }

        return (float) $value;
    }

    /**
     * `inset-0` / `inset-x-2` / `inset-y-4` — one spacing value applied to
     * several position edges at once. Anchoring an absolute child on opposing
     * edges is what stretches it to fill its parent, which is how `inset-0`
     * behaves in CSS.
     *
     * @param  list<string>  $edges
     * @return array<string, mixed>|null
     */
    /**
     * Mark explicitly-authored zero insets as IEEE -0.0. The wire's packed
     * node has no spare byte to distinguish "unset" from "explicit 0", so
     * +0.0 means unset and the sign bit carries "the author wrote
     * `bottom-0`" — which must anchor to the bottom edge, not fall through
     * to the top default. -0.0 survives the f32 wire bit-exactly; the
     * native layout treats `!= 0 || signbit` as "edge is set".
     */
    private static function explicitInsets(?array $parsed): ?array
    {
        if ($parsed === null) {
            return null;
        }

        foreach ($parsed as $key => $value) {
            if ($value === 0 || $value === 0.0) {
                $parsed[$key] = -0.0;
            }
        }

        return $parsed;
    }

    private static function parseInset(string $value, array $edges): ?array
    {
        $result = [];

        foreach ($edges as $edge) {
            $parsed = self::parseSpacingUniform($edge, $value);

            if ($parsed === null) {
                return null;
            }

            $result += $parsed;
        }

        return $result;
    }

    /**
     * Gradient directions, as Tailwind's `to-<edge>` suffix. The value is the
     * unit vector the gradient travels TOWARD, in view space where y grows
     * downward — the native side turns it into start/end unit points.
     *
     * @var array<string, array{float, float}>
     */
    private const GRADIENT_DIRECTIONS = [
        't' => [0.0, -1.0],
        'b' => [0.0, 1.0],
        'l' => [-1.0, 0.0],
        'r' => [1.0, 0.0],
        'tl' => [-1.0, -1.0],
        'tr' => [1.0, -1.0],
        'bl' => [-1.0, 1.0],
        'br' => [1.0, 1.0],
    ];

    /**
     * `bg-gradient-to-t` / `bg-linear-to-br` — the gradient's axis.
     *
     * Emitted as a `gradient` sub-array that the collector forwards whole;
     * direction and stops merge into it independently, so the classes may
     * appear in any order (Tailwind imposes none either).
     *
     * @return array{gradient: array{direction: array{float, float}}}|null
     */
    private static function parseGradientDirection(string $edge): ?array
    {
        $vector = self::GRADIENT_DIRECTIONS[$edge] ?? null;

        if ($vector === null) {
            return null;
        }

        return ['gradient' => ['direction' => $vector]];
    }

    /**
     * `from-black`, `via-black/10`, `to-transparent` — one gradient colour
     * stop. Accepts the full colour grammar (palette names, hex, `/N` opacity)
     * plus `theme-<token>`, so a gradient can be themed like any other fill.
     *
     * A stop with no gradient direction is inert: the native side only paints
     * when it has both an axis and at least two stops.
     *
     * @return array{gradient: array<string, string>}|null
     */
    private static function parseGradientStop(string $position, string $value): ?array
    {
        $hex = str_starts_with($value, 'theme-')
            ? self::resolveThemeToken(substr($value, 6), false)
            : self::resolveColorValue($value);

        if ($hex === null) {
            return null;
        }

        return ['gradient' => [$position => $hex]];
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
     * `bg-theme-<token>` — emit BOTH the light hex and a dark companion (when
     * a dark resolver is registered). The collector splits the dark portion
     * into `dark_bg_color` props that the native render layer
     * (`NodeStyleModifier`) picks at draw time based on system colorScheme.
     *
     * If no dark resolver is registered, only the light hex is emitted —
     * which means the class behaves identically in light and dark mode.
     */
    private static function parseThemeBg(string $token): ?array
    {
        $light = self::resolveThemeToken($token, false);
        if ($light === null) {
            return null;
        }
        $dark = self::resolveThemeToken($token, true);
        $out = ['bg' => $light];
        if ($dark !== null && $dark !== $light) {
            $out['dark'] = ['bg' => $dark];
        }

        return $out;
    }

    private static function parseThemeText(string $token): ?array
    {
        $light = self::resolveThemeToken($token, false);
        if ($light === null) {
            return null;
        }
        $dark = self::resolveThemeToken($token, true);
        $out = ['color' => $light];
        if ($dark !== null && $dark !== $light) {
            $out['dark'] = ['color' => $dark];
        }

        return $out;
    }

    private static function parseThemeBorder(string $token): ?array
    {
        $light = self::resolveThemeToken($token, false);
        if ($light === null) {
            return null;
        }
        $dark = self::resolveThemeToken($token, true);
        $out = ['borderColor' => $light, 'borderWidth' => 1];
        if ($dark !== null && $dark !== $light) {
            $out['dark'] = ['borderColor' => $dark];
        }

        return $out;
    }

    private static function resolveThemeToken(string $token, bool $dark = false): ?string
    {
        $resolver = $dark ? static::$themeDarkResolver : static::$themeResolver;
        if ($resolver === null) {
            return null;
        }
        $resolved = call_user_func($resolver, $token);

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
            'left' => ['textAlign' => TextAlign::Left->value],
            'center' => ['textAlign' => TextAlign::Center->value],
            'right' => ['textAlign' => TextAlign::Right->value],
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

    /**
     * `rounded-*` — uniform, per-side and per-corner.
     *
     * The scale keys carry no dashes, so a dash unambiguously separates a
     * side from its size: `2xl` is uniform, `br-none` is one corner.
     * A bare side (`rounded-t`) takes Tailwind's default 4pt radius, the
     * same as a bare `rounded`.
     *
     * Per-corner keys are emitted ALONGSIDE any uniform `borderRadius` rather
     * than merged into it, so `rounded-2xl rounded-br-none` keeps both and the
     * collector resolves the precedence. That makes the result independent of
     * the order the classes appear in — which matches Tailwind, where the
     * longhand always follows the shorthand in the generated stylesheet
     * regardless of how the author ordered the attribute.
     */
    private static function parseRounded(string $value): ?array
    {
        if (isset(self::BORDER_RADIUS[$value])) {
            return ['borderRadius' => self::BORDER_RADIUS[$value]];
        }

        [$side, $size] = array_pad(explode('-', $value, 2), 2, null);

        $corners = self::BORDER_RADIUS_CORNERS[$side] ?? null;
        if ($corners === null) {
            return null;
        }

        // Bare side (`rounded-b`) → the same default `rounded` uses.
        $radius = $size === null ? 4 : (self::BORDER_RADIUS[$size] ?? null);
        if ($radius === null) {
            return null;
        }

        return array_fill_keys($corners, $radius);
    }

    /**
     * Arbitrary per-corner radius — `rounded-br-[4px]`, `rounded-t-[12]`.
     * The uniform `rounded-[N]` form is handled inline in parseArbitrary.
     *
     * @return array<string, float>|null
     */
    private static function parseArbitraryRounded(string $side, string $value): ?array
    {
        $corners = self::BORDER_RADIUS_CORNERS[$side] ?? null;

        return $corners === null ? null : array_fill_keys($corners, (float) $value);
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
        return ($case = AlignItems::fromUtilityClass($value)) !== null
            ? ['alignItems' => $case->value]
            : null;
    }

    private static function parseJustifyContent(string $value): ?array
    {
        return ($case = JustifyContent::fromUtilityClass($value)) !== null
            ? ['justifyContent' => $case->value]
            : null;
    }

    private static function parseAlignSelf(string $value): ?array
    {
        return ($case = AlignSelf::fromUtilityClass($value)) !== null
            ? ['alignSelf' => $case->value]
            : null;
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
            'min-w' => ['minWidth' => (float) $value],
            'max-w' => ['maxWidth' => (float) $value],
            'min-h' => ['minHeight' => (float) $value],
            'max-h' => ['maxHeight' => (float) $value],
            'bg' => $isColor ? self::arbitraryColor('bg', $value) : null,
            'text' => $isColor ? self::arbitraryColor('color', $value) : ['fontSize' => (float) $value],
            'rounded' => ['borderRadius' => (float) $value],
            // `rounded-br-[4px]` etc. The arbitrary regex is non-greedy up to
            // the final `-[`, so the whole `rounded-<side>` arrives as prefix.
            'rounded-tl', 'rounded-tr', 'rounded-br', 'rounded-bl',
            'rounded-t', 'rounded-r', 'rounded-b', 'rounded-l' => self::parseArbitraryRounded(substr($prefix, 8), $value),
            'border' => $isColor ? self::arbitraryColor('borderColor', $value) : ['borderWidth' => (float) $value],
            'opacity' => ['opacity' => (float) $value],
            'aspect' => ['aspectRatio' => self::parseRatio($value)],
            // Line height: `leading-[24px]` → absolute; `leading-[1.4]` →
            // unitless multiplier of the font size.
            'leading' => str_ends_with($value, 'px')
                ? ['lineHeightPx' => (float) substr($value, 0, -2)]
                : (is_numeric($value) ? ['lineHeight' => (float) $value] : null),
            'top' => ['positionTop' => (float) $value],
            'right' => ['positionRight' => (float) $value],
            'bottom' => ['positionBottom' => (float) $value],
            'left' => ['positionLeft' => (float) $value],
            default => null,
        };
    }

    private static function resolveColor(string $value, string $key): ?array
    {
        $hex = self::paletteHex($value);

        return $hex === null ? null : [$key => $hex];
    }

    private static function arbitraryColor(string $key, string $value): ?array
    {
        $hex = self::normalizeHex($value);

        return $hex === null ? null : [$key => $hex];
    }

    /**
     * Look up a `family-shade` palette name (e.g. `red-300`) and return its
     * hex, or null when the name isn't in the palette.
     */
    private static function paletteHex(string $value): ?string
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

        return self::COLORS[$family][(int) $shade] ?? null;
    }

    /**
     * Normalize authored CSS hex to wire format.
     *
     * Authoring is CSS byte order — `#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA` —
     * but the native ColorParsers read 8-digit hex as Android-style
     * `#AARRGGBB`, so alpha-bearing inputs get their alpha byte moved to
     * the front here. Returns null for invalid lengths or non-hex digits
     * (previously such values shipped raw and rendered as the wrong color
     * or fell back to black natively).
     */
    private static function normalizeHex(string $hex): ?string
    {
        $hex = strtoupper(ltrim($hex, '#'));

        if (! ctype_xdigit($hex)) {
            return null;
        }

        return match (strlen($hex)) {
            3 => '#'.$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2],
            4 => '#'.$hex[3].$hex[3].$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2],
            6 => '#'.$hex,
            8 => '#'.substr($hex, 6, 2).substr($hex, 0, 6),
            default => null,
        };
    }
}
