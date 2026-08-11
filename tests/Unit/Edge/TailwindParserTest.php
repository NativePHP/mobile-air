<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function () {
    TailwindParser::clearCache();
    ElementRegistry::reset();
    ElementRegistry::register('text', Text::class);
});

// ── Spacing ─────────────────────────────────────────

it('parses uniform padding', function () {
    expect(TailwindParser::parse('p-4'))->toBe(['padding' => 16]);
    expect(TailwindParser::parse('p-0'))->toBe(['padding' => 0]);
    expect(TailwindParser::parse('p-px'))->toBe(['padding' => 1]);
    expect(TailwindParser::parse('p-0.5'))->toBe(['padding' => 2]);
    expect(TailwindParser::parse('p-96'))->toBe(['padding' => 384]);
});

it('parses directional padding', function () {
    expect(TailwindParser::parse('pt-4'))->toBe(['paddingTop' => 16]);
    expect(TailwindParser::parse('pr-2'))->toBe(['paddingRight' => 8]);
    expect(TailwindParser::parse('pb-6'))->toBe(['paddingBottom' => 24]);
    expect(TailwindParser::parse('pl-8'))->toBe(['paddingLeft' => 32]);
});

it('parses axis padding', function () {
    expect(TailwindParser::parse('px-4'))->toBe(['paddingLeft' => 16, 'paddingRight' => 16]);
    expect(TailwindParser::parse('py-2'))->toBe(['paddingTop' => 8, 'paddingBottom' => 8]);
});

it('parses uniform margin', function () {
    expect(TailwindParser::parse('m-4'))->toBe(['margin' => 16]);
});

it('parses directional margin', function () {
    expect(TailwindParser::parse('mt-2'))->toBe(['marginTop' => 8]);
    expect(TailwindParser::parse('mr-3'))->toBe(['marginRight' => 12]);
    expect(TailwindParser::parse('mb-4'))->toBe(['marginBottom' => 16]);
    expect(TailwindParser::parse('ml-5'))->toBe(['marginLeft' => 20]);
});

it('parses axis margin', function () {
    expect(TailwindParser::parse('mx-4'))->toBe(['marginLeft' => 16, 'marginRight' => 16]);
    expect(TailwindParser::parse('my-2'))->toBe(['marginTop' => 8, 'marginBottom' => 8]);
});

it('parses gap', function () {
    expect(TailwindParser::parse('gap-2'))->toBe(['gap' => 8]);
    expect(TailwindParser::parse('gap-4'))->toBe(['gap' => 16]);
});

// ── Dimensions ──────────────────────────────────────

it('parses width from spacing scale', function () {
    expect(TailwindParser::parse('w-64'))->toBe(['width' => 256]);
    expect(TailwindParser::parse('w-0'))->toBe(['width' => 0]);
});

it('parses w-full and h-full', function () {
    expect(TailwindParser::parse('w-full'))->toBe(['fillWidth' => true]);
    expect(TailwindParser::parse('h-full'))->toBe(['fillHeight' => true]);
});

it('parses fractional widths', function () {
    expect(TailwindParser::parse('w-1/2'))->toBe(['width' => '50%']);
    expect(TailwindParser::parse('w-1/3'))->toBe(['width' => '33%']);
    expect(TailwindParser::parse('w-2/3'))->toBe(['width' => '67%']);
    expect(TailwindParser::parse('w-3/4'))->toBe(['width' => '75%']);
});

it('parses height from spacing scale', function () {
    expect(TailwindParser::parse('h-32'))->toBe(['height' => 128]);
});

// ── Min/max size constraints (#310) ─────────────────

it('parses min/max size constraints from the spacing scale', function () {
    expect(TailwindParser::parse('max-w-64'))->toBe(['maxWidth' => 256]);
    expect(TailwindParser::parse('min-w-4'))->toBe(['minWidth' => 16]);
    expect(TailwindParser::parse('max-h-96'))->toBe(['maxHeight' => 384]);
    expect(TailwindParser::parse('min-h-8'))->toBe(['minHeight' => 32]);
});

it('parses max-w from the container scale', function () {
    expect(TailwindParser::parse('max-w-xs'))->toBe(['maxWidth' => 320]);
    expect(TailwindParser::parse('max-w-sm'))->toBe(['maxWidth' => 384]);
    expect(TailwindParser::parse('max-w-2xl'))->toBe(['maxWidth' => 672]);
});

it('parses arbitrary min/max size constraints', function () {
    expect(TailwindParser::parse('max-w-[280px]'))->toBe(['maxWidth' => 280.0]);
    expect(TailwindParser::parse('min-h-[100px]'))->toBe(['minHeight' => 100.0]);
});

it('treats max-w-none as an explicit no-constraint', function () {
    // 0 is what "unset" already means on the wire, so `none` round-trips to it.
    expect(TailwindParser::parse('max-w-none'))->toBe(['maxWidth' => 0]);
});

it('combines a width mode with a max-width constraint', function () {
    expect(TailwindParser::parse('w-full max-w-[280px]'))
        ->toBe(['fillWidth' => true, 'maxWidth' => 280.0]);
});

it('drops max-w keywords the wire cannot express', function () {
    // min/max ride the packed node as bare floats with no companion size
    // mode, so there is nowhere to put "100% of the parent". Dropping them
    // surfaces the class in the unsupported-class diagnostics instead of
    // silently rendering nothing.
    expect(TailwindParser::parse('max-w-full'))->toBe([]);
    expect(TailwindParser::parse('max-w-screen'))->toBe([]);
});

it('does not let the margin branch swallow min/max classes', function () {
    expect(TailwindParser::parse('m-4'))->toBe(['margin' => 16]);
    expect(TailwindParser::parse('mx-2'))->toBe(['marginLeft' => 8, 'marginRight' => 8]);
});

// ── Flex & Alignment ────────────────────────────────

it('parses flex direction', function () {
    expect(TailwindParser::parse('flex-row'))->toBe(['flexDirection' => 1]);
    expect(TailwindParser::parse('flex-row-reverse'))->toBe(['flexDirection' => 1]);
    expect(TailwindParser::parse('flex-col'))->toBe(['flexDirection' => 0]);
    expect(TailwindParser::parse('flex-col-reverse'))->toBe(['flexDirection' => 0]);
});

it('parses flex utilities', function () {
    // flex-1 is `flex: 1 1 0%` in Tailwind — grow, shrink, AND zero basis.
    expect(TailwindParser::parse('flex-1'))->toBe(['flexGrow' => 1, 'flexShrink' => 1, 'flexBasis' => 0]);
    expect(TailwindParser::parse('flex-grow'))->toBe(['flexGrow' => 1]);
    expect(TailwindParser::parse('flex-grow-0'))->toBe(['flexGrow' => 0]);
    expect(TailwindParser::parse('flex-shrink'))->toBe(['flexShrink' => 1]);
    expect(TailwindParser::parse('flex-shrink-0'))->toBe(['flexShrink' => 0]);
});

it('parses the current Tailwind grow and shrink aliases', function () {
    expect(TailwindParser::parse('grow'))->toBe(['flexGrow' => 1]);
    expect(TailwindParser::parse('grow-0'))->toBe(['flexGrow' => 0]);
    expect(TailwindParser::parse('shrink'))->toBe(['flexShrink' => 1]);
    expect(TailwindParser::parse('shrink-0'))->toBe(['flexShrink' => 0]);
});

it('parses items alignment', function () {
    // start is 4, not 0 — 0 is the "no items-* class" slot. See AlignItems.
    expect(TailwindParser::parse('items-start'))->toBe(['alignItems' => 4]);
    expect(TailwindParser::parse('items-center'))->toBe(['alignItems' => 1]);
    expect(TailwindParser::parse('items-end'))->toBe(['alignItems' => 2]);
    expect(TailwindParser::parse('items-stretch'))->toBe(['alignItems' => 3]);
});

it('distinguishes an authored items-start from no alignment at all', function () {
    // The whole point of moving Start off 0 (#309): these two must not
    // produce the same wire value, or the renderers cannot tell them apart.
    NativeElementCollector::reset();
    NativeElementCollector::leaf('column', ['class' => 'items-start']);
    $explicit = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    NativeElementCollector::reset();
    NativeElementCollector::leaf('column', ['class' => 'p-4']);
    $unset = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($explicit['layout']['align_items'])->toBe(4)
        ->and($unset['layout'])->not->toHaveKey('align_items');
});

it('parses justify content', function () {
    expect(TailwindParser::parse('justify-start'))->toBe(['justifyContent' => 0]);
    expect(TailwindParser::parse('justify-center'))->toBe(['justifyContent' => 1]);
    expect(TailwindParser::parse('justify-end'))->toBe(['justifyContent' => 2]);
    expect(TailwindParser::parse('justify-between'))->toBe(['justifyContent' => 3]);
    expect(TailwindParser::parse('justify-around'))->toBe(['justifyContent' => 4]);
    expect(TailwindParser::parse('justify-evenly'))->toBe(['justifyContent' => 5]);
});

it('parses self alignment', function () {
    // Both renderers resolve `alignSelf > 0 ? alignSelf : align`, so while
    // Start was 0 an authored `self-start` was a silent no-op. 4 fixes it.
    expect(TailwindParser::parse('self-start'))->toBe(['alignSelf' => 4]);
    expect(TailwindParser::parse('self-center'))->toBe(['alignSelf' => 1]);
    expect(TailwindParser::parse('self-end'))->toBe(['alignSelf' => 2]);
    expect(TailwindParser::parse('self-stretch'))->toBe(['alignSelf' => 3]);
});

// ── Colors ──────────────────────────────────────────

it('parses background colors', function () {
    expect(TailwindParser::parse('bg-blue-500'))->toBe(['bg' => '#3B82F6']);
    expect(TailwindParser::parse('bg-red-600'))->toBe(['bg' => '#DC2626']);
    expect(TailwindParser::parse('bg-white'))->toBe(['bg' => '#FFFFFF']);
    expect(TailwindParser::parse('bg-black'))->toBe(['bg' => '#000000']);
    expect(TailwindParser::parse('bg-transparent'))->toBe(['bg' => '#00000000']);
});

it('parses text colors', function () {
    expect(TailwindParser::parse('text-white'))->toBe(['color' => '#FFFFFF']);
    expect(TailwindParser::parse('text-black'))->toBe(['color' => '#000000']);
    expect(TailwindParser::parse('text-gray-900'))->toBe(['color' => '#111827']);
    expect(TailwindParser::parse('text-blue-500'))->toBe(['color' => '#3B82F6']);
    expect(TailwindParser::parse('text-red-400'))->toBe(['color' => '#F87171']);
});

it('parses all color families', function () {
    $families = [
        'slate', 'gray', 'zinc', 'neutral', 'stone',
        'red', 'orange', 'amber', 'yellow', 'lime',
        'green', 'emerald', 'teal', 'cyan', 'sky',
        'blue', 'indigo', 'violet', 'purple', 'fuchsia',
        'pink', 'rose',
    ];

    foreach ($families as $family) {
        $result = TailwindParser::parse("bg-{$family}-500");
        expect($result)->toHaveKey('bg');
        expect($result['bg'])->toStartWith('#');
    }
});

it('parses all shade levels', function () {
    $shades = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    foreach ($shades as $shade) {
        $result = TailwindParser::parse("bg-blue-{$shade}");
        expect($result)->toHaveKey('bg');
    }
});

// ── Typography ──────────────────────────────────────

it('parses font sizes', function () {
    expect(TailwindParser::parse('text-xs'))->toBe(['fontSize' => 12]);
    expect(TailwindParser::parse('text-sm'))->toBe(['fontSize' => 14]);
    expect(TailwindParser::parse('text-base'))->toBe(['fontSize' => 16]);
    expect(TailwindParser::parse('text-lg'))->toBe(['fontSize' => 18]);
    expect(TailwindParser::parse('text-xl'))->toBe(['fontSize' => 20]);
    expect(TailwindParser::parse('text-2xl'))->toBe(['fontSize' => 24]);
    expect(TailwindParser::parse('text-3xl'))->toBe(['fontSize' => 30]);
    expect(TailwindParser::parse('text-4xl'))->toBe(['fontSize' => 36]);
    expect(TailwindParser::parse('text-5xl'))->toBe(['fontSize' => 48]);
    expect(TailwindParser::parse('text-6xl'))->toBe(['fontSize' => 60]);
});

it('parses line height (leading) multipliers', function () {
    expect(TailwindParser::parse('leading-none'))->toBe(['lineHeight' => 1.0]);
    expect(TailwindParser::parse('leading-tight'))->toBe(['lineHeight' => 1.25]);
    expect(TailwindParser::parse('leading-snug'))->toBe(['lineHeight' => 1.375]);
    expect(TailwindParser::parse('leading-normal'))->toBe(['lineHeight' => 1.5]);
    expect(TailwindParser::parse('leading-relaxed'))->toBe(['lineHeight' => 1.625]);
    expect(TailwindParser::parse('leading-loose'))->toBe(['lineHeight' => 2.0]);
});

it('parses arbitrary line height (multiplier vs absolute px)', function () {
    expect(TailwindParser::parse('leading-[1.4]'))->toBe(['lineHeight' => 1.4]);
    expect(TailwindParser::parse('leading-[24px]'))->toBe(['lineHeightPx' => 24.0]);
    // Non-numeric arbitrary values are ignored, not emitted.
    expect(TailwindParser::parse('leading-[bogus]'))->toBe([]);
});

it('parses font weights', function () {
    expect(TailwindParser::parse('font-thin'))->toBe(['fontWeight' => 1]);
    expect(TailwindParser::parse('font-light'))->toBe(['fontWeight' => 2]);
    expect(TailwindParser::parse('font-normal'))->toBe(['fontWeight' => 3]);
    expect(TailwindParser::parse('font-medium'))->toBe(['fontWeight' => 4]);
    expect(TailwindParser::parse('font-semibold'))->toBe(['fontWeight' => 5]);
    expect(TailwindParser::parse('font-bold'))->toBe(['fontWeight' => 6]);
    expect(TailwindParser::parse('font-extrabold'))->toBe(['fontWeight' => 7]);
});

it('parses text alignment', function () {
    expect(TailwindParser::parse('text-left'))->toBe(['textAlign' => 0]);
    expect(TailwindParser::parse('text-center'))->toBe(['textAlign' => 1]);
    expect(TailwindParser::parse('text-right'))->toBe(['textAlign' => 2]);
});

// ── Borders ─────────────────────────────────────────

it('parses border width', function () {
    expect(TailwindParser::parse('border'))->toBe(['borderWidth' => 1]);
    expect(TailwindParser::parse('border-0'))->toBe(['borderWidth' => 0]);
    expect(TailwindParser::parse('border-2'))->toBe(['borderWidth' => 2]);
    expect(TailwindParser::parse('border-4'))->toBe(['borderWidth' => 4]);
    expect(TailwindParser::parse('border-8'))->toBe(['borderWidth' => 8]);
});

it('parses border color', function () {
    expect(TailwindParser::parse('border-gray-300'))->toBe(['borderColor' => '#D1D5DB']);
    expect(TailwindParser::parse('border-red-500'))->toBe(['borderColor' => '#EF4444']);
    expect(TailwindParser::parse('border-white'))->toBe(['borderColor' => '#FFFFFF']);
    expect(TailwindParser::parse('border-black'))->toBe(['borderColor' => '#000000']);
});

it('parses border radius', function () {
    expect(TailwindParser::parse('rounded'))->toBe(['borderRadius' => 4]);
    expect(TailwindParser::parse('rounded-none'))->toBe(['borderRadius' => 0]);
    expect(TailwindParser::parse('rounded-sm'))->toBe(['borderRadius' => 2]);
    expect(TailwindParser::parse('rounded-md'))->toBe(['borderRadius' => 6]);
    expect(TailwindParser::parse('rounded-lg'))->toBe(['borderRadius' => 8]);
    expect(TailwindParser::parse('rounded-xl'))->toBe(['borderRadius' => 12]);
    expect(TailwindParser::parse('rounded-2xl'))->toBe(['borderRadius' => 16]);
    expect(TailwindParser::parse('rounded-3xl'))->toBe(['borderRadius' => 24]);
    expect(TailwindParser::parse('rounded-full'))->toBe(['borderRadius' => 9999]);
});

// ── Visual ──────────────────────────────────────────

it('parses opacity', function () {
    expect(TailwindParser::parse('opacity-0'))->toBe(['opacity' => 0.0]);
    expect(TailwindParser::parse('opacity-50'))->toBe(['opacity' => 0.5]);
    expect(TailwindParser::parse('opacity-100'))->toBe(['opacity' => 1.0]);
    expect(TailwindParser::parse('opacity-75'))->toBe(['opacity' => 0.75]);
});

it('parses shadow/elevation', function () {
    expect(TailwindParser::parse('shadow'))->toBe(['elevation' => 3]);
    expect(TailwindParser::parse('shadow-sm'))->toBe(['elevation' => 1]);
    expect(TailwindParser::parse('shadow-md'))->toBe(['elevation' => 6]);
    expect(TailwindParser::parse('shadow-lg'))->toBe(['elevation' => 8]);
    expect(TailwindParser::parse('shadow-xl'))->toBe(['elevation' => 12]);
    expect(TailwindParser::parse('shadow-2xl'))->toBe(['elevation' => 16]);
    expect(TailwindParser::parse('shadow-none'))->toBe(['elevation' => 0]);
});

it('parses safe-area', function () {
    expect(TailwindParser::parse('safe-area'))->toBe(['safeArea' => true]);
});

// ── Arbitrary Values ────────────────────────────────

it('parses arbitrary padding', function () {
    expect(TailwindParser::parse('p-[13]'))->toBe(['padding' => 13.0]);
    expect(TailwindParser::parse('px-[20]'))->toBe(['paddingLeft' => 20.0, 'paddingRight' => 20.0]);
    expect(TailwindParser::parse('py-[10]'))->toBe(['paddingTop' => 10.0, 'paddingBottom' => 10.0]);
    expect(TailwindParser::parse('pt-[6]'))->toBe(['paddingTop' => 6.0]);
});

it('parses arbitrary margin', function () {
    expect(TailwindParser::parse('m-[15]'))->toBe(['margin' => 15.0]);
    expect(TailwindParser::parse('mt-[6]'))->toBe(['marginTop' => 6.0]);
});

it('parses arbitrary gap and dimensions', function () {
    expect(TailwindParser::parse('gap-[6]'))->toBe(['gap' => 6.0]);
    expect(TailwindParser::parse('w-[200]'))->toBe(['width' => 200.0]);
    expect(TailwindParser::parse('h-[100]'))->toBe(['height' => 100.0]);
});

it('parses arbitrary colors', function () {
    expect(TailwindParser::parse('bg-[#FF5733]'))->toBe(['bg' => '#FF5733']);
    expect(TailwindParser::parse('text-[#333]'))->toBe(['color' => '#333333']);
    expect(TailwindParser::parse('border-[#ccc]'))->toBe(['borderColor' => '#CCCCCC']);
});

it('converts arbitrary CSS alpha hex to wire ARGB order', function () {
    // Authored #RRGGBBAA; native ColorParsers read #AARRGGBB.
    expect(TailwindParser::parse('bg-[#8B5CF680]'))->toBe(['bg' => '#808B5CF6']);
    expect(TailwindParser::parse('text-[#F00C]'))->toBe(['color' => '#CCFF0000']);
});

it('drops arbitrary colors with invalid hex', function () {
    expect(TailwindParser::parse('bg-[#12345]'))->toBe([]);
    expect(TailwindParser::parse('bg-[#GGHHII]'))->toBe([]);
});

it('applies slash opacity modifiers to color classes', function () {
    expect(TailwindParser::parse('bg-red-500/50'))->toBe(['bg' => '#80EF4444']);
    expect(TailwindParser::parse('text-red-300/20'))->toBe(['color' => '#33FCA5A5']);
    expect(TailwindParser::parse('bg-red-500/[27]'))->toBe(['bg' => '#45EF4444']);
});

// ── Color value resolution (theme config / element props) ──

it('resolves palette names to hex', function () {
    expect(TailwindParser::resolveColorValue('red-300'))->toBe('#FCA5A5');
    expect(TailwindParser::resolveColorValue('orange-800'))->toBe('#9A3412');
    expect(TailwindParser::resolveColorValue('slate-950'))->toBe('#020617');
});

it('resolves palette names with opacity modifiers', function () {
    expect(TailwindParser::resolveColorValue('red-300/20'))->toBe('#33FCA5A5');
    expect(TailwindParser::resolveColorValue('orange-800/50'))->toBe('#809A3412');
    expect(TailwindParser::resolveColorValue('red-300/[27]'))->toBe('#45FCA5A5');
});

it('resolves CSS hex values to wire format', function () {
    expect(TailwindParser::resolveColorValue('#B91C1C'))->toBe('#B91C1C');
    expect(TailwindParser::resolveColorValue('#f00'))->toBe('#FF0000');
    // Authored CSS #RRGGBBAA / #RGBA → wire #AARRGGBB.
    expect(TailwindParser::resolveColorValue('#8B5CF680'))->toBe('#808B5CF6');
    expect(TailwindParser::resolveColorValue('#F00C'))->toBe('#CCFF0000');
    // Slash opacity on hex; overwrites any authored alpha.
    expect(TailwindParser::resolveColorValue('#8B5CF6/50'))->toBe('#808B5CF6');
    expect(TailwindParser::resolveColorValue('#8B5CF680/100'))->toBe('#FF8B5CF6');
});

it('resolves special color names', function () {
    expect(TailwindParser::resolveColorValue('white'))->toBe('#FFFFFF');
    expect(TailwindParser::resolveColorValue('black/50'))->toBe('#80000000');
    expect(TailwindParser::resolveColorValue('transparent'))->toBe('#00000000');
});

it('returns null for unrecognized color values', function () {
    expect(TailwindParser::resolveColorValue('System'))->toBeNull();
    expect(TailwindParser::resolveColorValue('red-9999'))->toBeNull();
    expect(TailwindParser::resolveColorValue('#12345'))->toBeNull();
    expect(TailwindParser::resolveColorValue('#GGHHII'))->toBeNull();
    expect(TailwindParser::resolveColorValue('red-300/high'))->toBeNull();
    expect(TailwindParser::resolveColorValue(''))->toBeNull();
});

it('parses arbitrary font size', function () {
    expect(TailwindParser::parse('text-[18]'))->toBe(['fontSize' => 18.0]);
    expect(TailwindParser::parse('text-[32]'))->toBe(['fontSize' => 32.0]);
});

it('parses arbitrary border radius and width', function () {
    expect(TailwindParser::parse('rounded-[12]'))->toBe(['borderRadius' => 12.0]);
    expect(TailwindParser::parse('border-[3]'))->toBe(['borderWidth' => 3.0]);
});

it('parses arbitrary opacity', function () {
    expect(TailwindParser::parse('opacity-[0.65]'))->toBe(['opacity' => 0.65]);
});

// ── Compound Classes ────────────────────────────────

it('parses multiple classes into merged attributes', function () {
    $result = TailwindParser::parse('flex-1 p-4 gap-2 bg-blue-500 rounded-lg');

    expect($result)->toBe([
        'flexGrow' => 1,
        'flexShrink' => 1,
        'flexBasis' => 0,
        'padding' => 16,
        'gap' => 8,
        'bg' => '#3B82F6',
        'borderRadius' => 8,
    ]);
});

it('parses a full text styling string', function () {
    $result = TailwindParser::parse('text-2xl font-bold text-white text-center');

    expect($result)->toBe([
        'fontSize' => 24,
        'fontWeight' => 6,
        'color' => '#FFFFFF',
        'textAlign' => 1,
    ]);
});

it('parses a full layout string', function () {
    $result = TailwindParser::parse('flex-1 items-center justify-between gap-4 p-6 safe-area');

    expect($result)->toBe([
        'flexGrow' => 1,
        'flexShrink' => 1,
        'flexBasis' => 0,
        'alignItems' => 1,
        'justifyContent' => 3,
        'gap' => 16,
        'padding' => 24,
        'safeArea' => true,
    ]);
});

it('parses border with color', function () {
    $result = TailwindParser::parse('border-2 border-gray-300 rounded-lg');

    expect($result)->toBe([
        'borderWidth' => 2,
        'borderColor' => '#D1D5DB',
        'borderRadius' => 8,
    ]);
});

it('combines axis padding correctly', function () {
    $result = TailwindParser::parse('px-4 py-2');

    expect($result)->toBe([
        'paddingLeft' => 16,
        'paddingRight' => 16,
        'paddingTop' => 8,
        'paddingBottom' => 8,
    ]);
});

it('later classes override earlier ones for same key', function () {
    $result = TailwindParser::parse('bg-red-500 bg-blue-500');
    expect($result)->toBe(['bg' => '#3B82F6']);
});

// ── Dark Mode ───────────────────────────────────────

it('parses dark: background color', function () {
    $result = TailwindParser::parse('bg-white dark:bg-gray-900');

    expect($result)->toBe([
        'bg' => '#FFFFFF',
        'dark' => ['bg' => '#111827'],
    ]);
});

it('parses dark: text color', function () {
    $result = TailwindParser::parse('text-black dark:text-white');

    expect($result)->toBe([
        'color' => '#000000',
        'dark' => ['color' => '#FFFFFF'],
    ]);
});

it('parses multiple dark: overrides', function () {
    $result = TailwindParser::parse('bg-white text-gray-900 dark:bg-gray-900 dark:text-white');

    expect($result)->toBe([
        'bg' => '#FFFFFF',
        'color' => '#111827',
        'dark' => [
            'bg' => '#111827',
            'color' => '#FFFFFF',
        ],
    ]);
});

it('parses dark: border color', function () {
    $result = TailwindParser::parse('border-gray-300 dark:border-gray-700');

    expect($result)->toBe([
        'borderColor' => '#D1D5DB',
        'dark' => ['borderColor' => '#374151'],
    ]);
});

it('parses dark: with arbitrary values', function () {
    $result = TailwindParser::parse('bg-[#FFFFFF] dark:bg-[#1a1a1a]');

    expect($result)->toBe([
        'bg' => '#FFFFFF',
        'dark' => ['bg' => '#1A1A1A'],
    ]);
});

it('parses dark: opacity', function () {
    $result = TailwindParser::parse('opacity-100 dark:opacity-80');

    expect($result)->toBe([
        'opacity' => 1.0,
        'dark' => ['opacity' => 0.8],
    ]);
});

it('ignores unknown dark: classes', function () {
    $result = TailwindParser::parse('dark:hover:bg-blue-500');
    expect($result)->toBe([]);
});

// ── Platform variants (ios: / android:) ─────────────

it('applies ios: classes only on ios', function () {
    TailwindParser::setPlatform('ios');
    $result = TailwindParser::parse('bg-white ios:bg-red-500 android:bg-blue-500');
    expect($result)->toBe(['bg' => '#EF4444']);

    TailwindParser::setPlatform(null);
});

it('applies android: classes only on android', function () {
    TailwindParser::setPlatform('android');
    $result = TailwindParser::parse('bg-white ios:bg-red-500 android:bg-blue-500');
    expect($result)->toBe(['bg' => '#3B82F6']);

    TailwindParser::setPlatform(null);
});

it('drops platform variants when no platform is set', function () {
    TailwindParser::setPlatform(null);
    $result = TailwindParser::parse('bg-white ios:bg-red-500 android:bg-blue-500');
    expect($result)->toBe(['bg' => '#FFFFFF']);
});

it('composes platform variants with dark variant', function () {
    TailwindParser::setPlatform('android');
    $result = TailwindParser::parse('bg-white android:dark:bg-gray-900');
    expect($result)->toBe([
        'bg' => '#FFFFFF',
        'dark' => ['bg' => '#111827'],
    ]);

    TailwindParser::setPlatform('ios');
    TailwindParser::clearCache();
    $result = TailwindParser::parse('bg-white android:dark:bg-gray-900');
    expect($result)->toBe(['bg' => '#FFFFFF']);

    TailwindParser::setPlatform(null);
});

it('composes platform variants in reverse order with dark', function () {
    TailwindParser::setPlatform('android');
    $result = TailwindParser::parse('bg-white dark:android:bg-gray-900');
    expect($result)->toBe([
        'bg' => '#FFFFFF',
        'dark' => ['bg' => '#111827'],
    ]);

    TailwindParser::setPlatform('ios');
    TailwindParser::clearCache();
    $result = TailwindParser::parse('bg-white dark:android:bg-gray-900');
    expect($result)->toBe(['bg' => '#FFFFFF']);

    TailwindParser::setPlatform(null);
});

// ── Edge Cases ──────────────────────────────────────

it('ignores unknown classes silently', function () {
    $result = TailwindParser::parse('hover:bg-blue-500 unknown-class grid-cols-3 animate-spin');
    expect($result)->toBe([]);
});

it('handles empty string', function () {
    expect(TailwindParser::parse(''))->toBe([]);
});

it('handles whitespace-only string', function () {
    expect(TailwindParser::parse('   '))->toBe([]);
});

it('handles extra whitespace between classes', function () {
    $result = TailwindParser::parse('  p-4   bg-white   ');
    expect($result)->toBe(['padding' => 16, 'bg' => '#FFFFFF']);
});

it('mixes known and unknown classes', function () {
    $result = TailwindParser::parse('p-4 hover:text-red-500 bg-blue-500 transition-all');

    expect($result)->toBe([
        'padding' => 16,
        'bg' => '#3B82F6',
    ]);
});

// ── Caching ─────────────────────────────────────────

it('returns cached result for same class string', function () {
    $first = TailwindParser::parse('p-4 bg-blue-500');
    $second = TailwindParser::parse('p-4 bg-blue-500');

    expect($first)->toBe($second);
});

// ── Integration with NativeElementCollector ─────────

it('works end-to-end with collector for column', function () {
    NativeElementCollector::reset();
    NativeElementCollector::open('column', [
        'class' => 'flex-1 p-6 gap-4 bg-white safe-area',
    ]);
    NativeElementCollector::leaf('text', [
        'text' => 'Hello',
        'class' => 'text-2xl font-bold text-gray-900',
    ]);
    NativeElementCollector::close();

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['type'])->toBe('column');
    expect($tree['layout']['flex_grow'])->toBe(1.0);
    expect($tree['layout']['padding'])->toBe(24.0);
    expect($tree['layout']['gap'])->toBe(16.0);
    expect($tree['layout']['safe_area'])->toBe(1);
    expect($tree['style']['bg_color'])->toBe('#FFFFFF');

    $text = $tree['children'][0];
    expect($text['props']['font_size'])->toBe(24.0);
    expect($text['props']['font_weight'])->toBe(6);
    expect($text['props']['color'])->toBe('#111827');
});

it('applies directional padding correctly through collector', function () {
    NativeElementCollector::reset();
    NativeElementCollector::leaf('column', [
        'class' => 'px-4 py-2',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout']['padding'])->toBe([8.0, 16.0, 8.0, 16.0]);
});

it('combines uniform and directional padding through collector', function () {
    NativeElementCollector::reset();
    NativeElementCollector::leaf('column', [
        'class' => 'p-4 pt-8',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    // p-4 = 16 uniform, pt-8 = 32 top override
    expect($tree['layout']['padding'])->toBe([32.0, 16.0, 16.0, 16.0]);
});

it('carries min/max size constraints through the collector to the wire', function () {
    NativeElementCollector::reset();
    NativeElementCollector::leaf('column', [
        'class' => 'w-full max-w-[280px] min-h-16',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout']['max_width'])->toBe(280.0)
        ->and($tree['layout']['min_height'])->toBe(64.0)
        // `w-full` still sets the width mode — max-w clamps it, it doesn't replace it.
        ->and($tree['layout']['width'])->toBe('fill');
});

it('explicit attrs override class attrs', function () {
    NativeElementCollector::reset();
    NativeElementCollector::leaf('text', [
        'text' => 'Hello',
        'class' => 'text-xl text-blue-500',
        'fontSize' => '32',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    // explicit fontSize=32 should override text-xl (20)
    expect($tree['props']['font_size'])->toBe(32.0);
});

// ── Negative utilities ──────────────────────────────

it('parses negative margins', function () {
    expect(TailwindParser::parse('-mt-4'))->toBe(['marginTop' => -16.0]);
    expect(TailwindParser::parse('-ml-2'))->toBe(['marginLeft' => -8.0]);
    expect(TailwindParser::parse('-m-1'))->toBe(['margin' => -4.0]);
    expect(TailwindParser::parse('-mx-4'))->toBe(['marginLeft' => -16.0, 'marginRight' => -16.0]);
    expect(TailwindParser::parse('-my-2'))->toBe(['marginTop' => -8.0, 'marginBottom' => -8.0]);
});

it('parses negative insets so an absolute child can overhang its container', function () {
    expect(TailwindParser::parse('-right-8'))->toBe(['positionRight' => -32.0]);
    expect(TailwindParser::parse('-top-2'))->toBe(['positionTop' => -8.0]);
    expect(TailwindParser::parse('-bottom-px'))->toBe(['positionBottom' => -1.0]);
    expect(TailwindParser::parse('-left-0.5'))->toBe(['positionLeft' => -2.0]);
});

it('parses negative arbitrary values', function () {
    expect(TailwindParser::parse('-right-[12]'))->toBe(['positionRight' => -12.0]);
    expect(TailwindParser::parse('-mt-[6]'))->toBe(['marginTop' => -6.0]);
});

it('composes negatives with platform and dark variants', function () {
    TailwindParser::setPlatform('ios');
    expect(TailwindParser::parse('ios:-right-8'))->toBe(['positionRight' => -32.0]);
    expect(TailwindParser::parse('android:-right-8'))->toBe([]);
    TailwindParser::setPlatform(null);

    expect(TailwindParser::parse('dark:-mt-4'))->toBe(['dark' => ['marginTop' => -16.0]]);
});

it('rejects negatives on utilities that have no negative form', function () {
    // Tailwind has no negative padding / gap / sizing; emitting geometry the
    // author never asked for would be worse than dropping the class.
    expect(TailwindParser::parse('-p-4'))->toBe([]);
    expect(TailwindParser::parse('-gap-2'))->toBe([]);
    expect(TailwindParser::parse('-w-4'))->toBe([]);
    expect(TailwindParser::parse('-bg-red-500'))->toBe([]);
    expect(TailwindParser::parse('-nonsense'))->toBe([]);
});

it('keeps positive spacing untouched', function () {
    expect(TailwindParser::parse('mt-4'))->toBe(['marginTop' => 16]);
    expect(TailwindParser::parse('right-8'))->toBe(['positionRight' => 32]);
});

// ── Linear gradients ────────────────────────────────

it('parses a gradient axis and its colour stops into one gradient key', function () {
    expect(TailwindParser::parse('bg-gradient-to-t from-black via-black/10 to-transparent'))
        ->toBe(['gradient' => [
            'direction' => [0.0, -1.0],
            'from' => '#000000',
            'via' => '#1A000000',
            'to' => '#00000000',
        ]]);
});

it('accepts the tailwind v4 bg-linear spelling and every direction', function () {
    expect(TailwindParser::parse('bg-linear-to-br from-black to-white')['gradient']['direction'])
        ->toBe([1.0, 1.0]);
    expect(TailwindParser::parse('bg-gradient-to-l from-black to-white')['gradient']['direction'])
        ->toBe([-1.0, 0.0]);
});

it('merges gradient classes regardless of the order they appear in', function () {
    // The parsed array's KEY order follows the class order, so compare the
    // emitted props, where stops are normalised to from → via → to.
    expect(NativeElementCollector::buildGradientProps(TailwindParser::parse('to-transparent from-black bg-gradient-to-t')))
        ->toBe(NativeElementCollector::buildGradientProps(TailwindParser::parse('bg-gradient-to-t from-black to-transparent')));
});

it('resolves theme tokens as gradient stops', function () {
    TailwindParser::setThemeResolver(fn (string $token) => $token === 'primary' ? '#9BE500' : null);

    expect(TailwindParser::parse('bg-gradient-to-t from-theme-primary to-transparent')['gradient']['from'])
        ->toBe('#9BE500');

    TailwindParser::setThemeResolver(null);
});

it('does not let a gradient swallow the plain bg- branch', function () {
    expect(TailwindParser::parse('bg-black'))->toBe(['bg' => '#000000']);
});

it('emits gradient props only when there is an axis and two stops', function () {
    $complete = TailwindParser::parse('bg-gradient-to-t from-black to-transparent');
    expect(NativeElementCollector::buildGradientProps($complete))->toBe([
        'gradient_dx' => 0.0,
        'gradient_dy' => -1.0,
        'gradient_stops' => '#000000,#00000000',
    ]);

    // A stop with no axis, and an axis with one stop, are both inert.
    expect(NativeElementCollector::buildGradientProps(TailwindParser::parse('from-black to-white')))->toBe([]);
    expect(NativeElementCollector::buildGradientProps(TailwindParser::parse('bg-gradient-to-t from-black')))->toBe([]);
    expect(NativeElementCollector::buildGradientProps(TailwindParser::parse('bg-gradient-to-nowhere from-black to-white')))->toBe([]);
});

// ── Inset shorthands ────────────────────────────────

it('expands inset shorthands to the position edges', function () {
    expect(TailwindParser::parse('inset-0'))->toBe([
        'positionTop' => -0.0, 'positionRight' => -0.0, 'positionBottom' => -0.0, 'positionLeft' => -0.0,
    ]);
    expect(TailwindParser::parse('inset-x-2'))->toBe(['positionLeft' => 8, 'positionRight' => 8]);
    expect(TailwindParser::parse('inset-y-4'))->toBe(['positionTop' => 16, 'positionBottom' => 16]);
    expect(TailwindParser::parse('inset-bogus'))->toBe([]);
});

it('marks authored zero insets with the -0.0 sentinel so bottom-0 can anchor', function () {
    // +0.0 on the wire means "edge unset"; an authored zero must be
    // distinguishable or `bottom-0` / `right-0` silently anchor top-left.
    // The sign bit is the only spare storage in the packed f32 slot.
    $bottom = TailwindParser::parse('bottom-0')['positionBottom'];
    expect(fdiv(1, $bottom))->toBe(-INF);

    $right = TailwindParser::parse('right-0')['positionRight'];
    expect(fdiv(1, $right))->toBe(-INF);

    // Non-zero insets are unaffected, negatives keep their bleed meaning.
    expect(TailwindParser::parse('bottom-2'))->toBe(['positionBottom' => 8]);
    expect(TailwindParser::parse('-right-8'))->toBe(['positionRight' => -32.0]);
});
