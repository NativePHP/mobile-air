<?php

use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function () {
    TailwindParser::clearCache();
    ElementRegistry::reset();
    ElementRegistry::register('button', Button::class);
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

// ── Flex & Alignment ────────────────────────────────

it('parses flex utilities', function () {
    expect(TailwindParser::parse('flex-1'))->toBe(['flexGrow' => 1]);
    expect(TailwindParser::parse('flex-grow'))->toBe(['flexGrow' => 1]);
    expect(TailwindParser::parse('flex-grow-0'))->toBe(['flexGrow' => 0]);
    expect(TailwindParser::parse('flex-shrink'))->toBe(['flexShrink' => 1]);
    expect(TailwindParser::parse('flex-shrink-0'))->toBe(['flexShrink' => 0]);
});

it('parses items alignment', function () {
    expect(TailwindParser::parse('items-start'))->toBe(['alignItems' => 0]);
    expect(TailwindParser::parse('items-center'))->toBe(['alignItems' => 1]);
    expect(TailwindParser::parse('items-end'))->toBe(['alignItems' => 2]);
    expect(TailwindParser::parse('items-stretch'))->toBe(['alignItems' => 3]);
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
    expect(TailwindParser::parse('self-start'))->toBe(['alignSelf' => 0]);
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
    \Native\Mobile\Edge\NativeElementCollector::reset();
    \Native\Mobile\Edge\NativeElementCollector::open('column', [
        'class' => 'flex-1 p-6 gap-4 bg-white safe-area',
    ]);
    \Native\Mobile\Edge\NativeElementCollector::leaf('text', [
        'text' => 'Hello',
        'class' => 'text-2xl font-bold text-gray-900',
    ]);
    \Native\Mobile\Edge\NativeElementCollector::close();

    $registry = new \Native\Mobile\Edge\CallbackRegistry;
    $tree = \Native\Mobile\Edge\NativeElementCollector::collect()->toArray($registry);

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

it('works end-to-end with collector for button', function () {
    \Native\Mobile\Edge\NativeElementCollector::reset();
    \Native\Mobile\Edge\NativeElementCollector::leaf('button', [
        'label' => 'Sign In',
        'class' => 'bg-blue-500 text-white rounded-lg py-3',
        '_press' => 'login',
    ]);

    $registry = new \Native\Mobile\Edge\CallbackRegistry;
    $tree = \Native\Mobile\Edge\NativeElementCollector::collect()->toArray($registry);

    expect($tree['type'])->toBe('button');
    expect($tree['props']['label'])->toBe('Sign In');
    expect($tree['props']['color'])->toBe('#3B82F6');
    expect($tree['props']['label_color'])->toBe('#FFFFFF');
    expect($tree['style']['border_radius'])->toBe(8.0);
    expect($tree['layout']['padding'])->toBeArray();
    expect($registry->resolve($tree['props']['on_press']))->toBe('login');
});

it('applies directional padding correctly through collector', function () {
    \Native\Mobile\Edge\NativeElementCollector::reset();
    \Native\Mobile\Edge\NativeElementCollector::leaf('column', [
        'class' => 'px-4 py-2',
    ]);

    $tree = \Native\Mobile\Edge\NativeElementCollector::collect()->toArray(new \Native\Mobile\Edge\CallbackRegistry);

    expect($tree['layout']['padding'])->toBe([8.0, 16.0, 8.0, 16.0]);
});

it('combines uniform and directional padding through collector', function () {
    \Native\Mobile\Edge\NativeElementCollector::reset();
    \Native\Mobile\Edge\NativeElementCollector::leaf('column', [
        'class' => 'p-4 pt-8',
    ]);

    $tree = \Native\Mobile\Edge\NativeElementCollector::collect()->toArray(new \Native\Mobile\Edge\CallbackRegistry);

    // p-4 = 16 uniform, pt-8 = 32 top override
    expect($tree['layout']['padding'])->toBe([32.0, 16.0, 16.0, 16.0]);
});

it('explicit attrs override class attrs', function () {
    \Native\Mobile\Edge\NativeElementCollector::reset();
    \Native\Mobile\Edge\NativeElementCollector::leaf('text', [
        'text' => 'Hello',
        'class' => 'text-xl text-blue-500',
        'fontSize' => '32',
    ]);

    $tree = \Native\Mobile\Edge\NativeElementCollector::collect()->toArray(new \Native\Mobile\Edge\CallbackRegistry);

    // explicit fontSize=32 should override text-xl (20)
    expect($tree['props']['font_size'])->toBe(32.0);
});

it('preserves backward compat with explicit button color attr', function () {
    \Native\Mobile\Edge\NativeElementCollector::reset();
    \Native\Mobile\Edge\NativeElementCollector::leaf('button', [
        'label' => 'Click',
        'color' => '#4CAF50',
        'labelColor' => '#FFFFFF',
    ]);

    $tree = \Native\Mobile\Edge\NativeElementCollector::collect()->toArray(new \Native\Mobile\Edge\CallbackRegistry);

    expect($tree['props']['color'])->toBe('#4CAF50');
    expect($tree['props']['label_color'])->toBe('#FFFFFF');
});
