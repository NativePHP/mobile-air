<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Enums\AlignItems;
use Native\Mobile\Edge\Enums\AlignSelf;
use Native\Mobile\Edge\Enums\JustifyContent;
use Native\Mobile\Edge\Enums\TextAlign;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function () {
    NativeElementCollector::reset();
});

afterEach(function () {
    NativeElementCollector::reset();
});

/*
 * The enum backing values ARE the binary wire contract read by the native
 * renderers (iOS FlexContainer / SwiftUINodeRenderer, Android
 * ComposeFlexLayout). If one of these drifts, alignment renders wrong on
 * device — so pin them.
 */
it('pins the alignment wire values to the native protocol', function () {
    expect([TextAlign::Left->value, TextAlign::Center->value, TextAlign::Right->value])
        ->toBe([0, 1, 2]);

    expect([
        AlignItems::Start->value, AlignItems::Center->value,
        AlignItems::End->value, AlignItems::Stretch->value,
    ])->toBe([0, 1, 2, 3]);

    expect([
        AlignSelf::Start->value, AlignSelf::Center->value,
        AlignSelf::End->value, AlignSelf::Stretch->value,
    ])->toBe([0, 1, 2, 3]);

    expect([
        JustifyContent::Start->value, JustifyContent::Center->value,
        JustifyContent::End->value, JustifyContent::SpaceBetween->value,
        JustifyContent::SpaceAround->value, JustifyContent::SpaceEvenly->value,
    ])->toBe([0, 1, 2, 3, 4, 5]);
});

it('resolves string labels to cases (case- and separator-tolerant)', function () {
    expect(AlignItems::fromLabel('center'))->toBe(AlignItems::Center);
    expect(AlignItems::fromLabel('CENTER'))->toBe(AlignItems::Center);
    expect(AlignItems::fromLabel('flex-start'))->toBe(AlignItems::Start);
    expect(AlignItems::fromLabel('  stretch '))->toBe(AlignItems::Stretch);

    expect(JustifyContent::fromLabel('between'))->toBe(JustifyContent::SpaceBetween);
    expect(JustifyContent::fromLabel('space-between'))->toBe(JustifyContent::SpaceBetween);
    expect(JustifyContent::fromLabel('space-evenly'))->toBe(JustifyContent::SpaceEvenly);

    expect(TextAlign::fromLabel('left'))->toBe(TextAlign::Left);
    expect(TextAlign::fromLabel('start'))->toBe(TextAlign::Left);
    expect(TextAlign::fromLabel('trailing'))->toBe(TextAlign::Right);
});

it('returns null for unknown labels', function () {
    expect(AlignItems::fromLabel('nonsense'))->toBeNull();
    // baseline was never a real native value — must not silently map.
    expect(AlignItems::fromLabel('baseline'))->toBeNull();
    expect(TextAlign::fromLabel(''))->toBeNull();
});

it('normalizes enums, ints, numeric strings and labels to the wire value', function () {
    expect(AlignItems::parse(AlignItems::Center))->toBe(1);

    // Raw ints (existing call sites) still work, but are validated against the
    // enum — an int outside the native domain resolves to null rather than
    // being forwarded to a renderer that can't read it.
    expect(AlignItems::parse(2))->toBe(2);
    expect(AlignItems::parse(99))->toBeNull();

    // Blade attributes arrive as strings.
    expect(AlignItems::parse('3'))->toBe(3);
    expect(JustifyContent::parse('space-around'))->toBe(4);

    // Unresolvable input yields null so callers can skip it.
    expect(AlignItems::parse('bogus'))->toBeNull();
    expect(AlignItems::parse(null))->toBeNull();
    expect(AlignItems::parse(['nope']))->toBeNull();
});

it('accepts enums, strings and ints on the fluent setters', function () {
    $byEnum = Column::make()
        ->alignItems(AlignItems::Center)
        ->justifyContent(JustifyContent::SpaceBetween)
        ->toArray(new CallbackRegistry);
    expect($byEnum['layout']['align_items'])->toBe(1);
    expect($byEnum['layout']['justify_content'])->toBe(3);

    $byString = Column::make()
        ->alignItems('end')
        ->alignSelf('stretch')
        ->toArray(new CallbackRegistry);
    expect($byString['layout']['align_items'])->toBe(2);
    expect($byString['layout']['align_self'])->toBe(3);

    // Backward compatibility: existing integer call sites are unchanged.
    $byInt = Column::make()->alignItems(1)->toArray(new CallbackRegistry);
    expect($byInt['layout']['align_items'])->toBe(1);

    $byTextEnum = Text::make('Hi')->textAlign(TextAlign::Right)->toArray(new CallbackRegistry);
    expect($byTextEnum['props']['text_align'])->toBe(2);

    $byTextString = Text::make('Hi')->textAlign('center')->toArray(new CallbackRegistry);
    expect($byTextString['props']['text_align'])->toBe(1);
});

it('ignores an unresolvable label instead of corrupting the value', function () {
    $el = Column::make()->alignItems('bogus')->toArray(new CallbackRegistry);

    expect($el['layout'] ?? [])->not->toHaveKey('align_items');
});

it('resolves alignment string attributes through the Blade collector', function () {
    NativeElementCollector::open('column', [
        'alignItems' => 'center',
        'justifyContent' => 'space-between',
        'alignSelf' => 'end',
    ]);
    NativeElementCollector::leaf('text', ['text' => 'x', 'textAlign' => 'right']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout']['align_items'])->toBe(1);
    expect($tree['layout']['justify_content'])->toBe(3);
    expect($tree['layout']['align_self'])->toBe(2);
    expect($tree['children'][0]['props']['text_align'])->toBe(2);
});

it('still accepts numeric string attributes for alignment', function () {
    NativeElementCollector::open('column', ['alignItems' => '3', 'justifyContent' => '5']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout']['align_items'])->toBe(3);
    expect($tree['layout']['justify_content'])->toBe(5);
});

it('keeps Tailwind alignment classes mapping to the same wire values', function () {
    expect(TailwindParser::parse('items-center'))->toMatchArray(['alignItems' => 1]);
    expect(TailwindParser::parse('items-stretch'))->toMatchArray(['alignItems' => 3]);
    expect(TailwindParser::parse('justify-between'))->toMatchArray(['justifyContent' => 3]);
    expect(TailwindParser::parse('justify-evenly'))->toMatchArray(['justifyContent' => 5]);
    expect(TailwindParser::parse('self-end'))->toMatchArray(['alignSelf' => 2]);
    expect(TailwindParser::parse('text-right'))->toMatchArray(['textAlign' => 2]);
});

it('rejects integers outside the enum instead of passing them to the wire', function () {
    // The native renderers switch on this value and silently fall back to
    // their own default for anything unknown, so an out-of-range int must not
    // reach them — that just moves the failure somewhere harder to see. This
    // is why the old `Align::BASELINE = 4` was removed.
    expect(AlignItems::parse(4))->toBeNull();
    expect(AlignItems::parse(-1))->toBeNull();
    expect(JustifyContent::parse(6))->toBeNull();
    expect(TextAlign::parse(3))->toBeNull();

    // ...and the setter leaves the native default in place rather than
    // writing a value the renderer can't read.
    $el = Column::make()->alignItems(4)->toArray(new CallbackRegistry);
    expect($el['layout'] ?? [])->not->toHaveKey('align_items');
});

it('rejects out-of-range numeric string attributes too', function () {
    NativeElementCollector::open('column', ['alignItems' => '9']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout'] ?? [])->not->toHaveKey('align_items');
});

it('keeps the friendly aliases out of the Tailwind class vocabulary', function () {
    // `fromLabel()` accepts aliases for the fluent PHP + Blade attribute APIs,
    // but utility classes must track Tailwind's own vocabulary. Otherwise
    // these become "valid" NativePHP classes that mean nothing in Tailwind.
    foreach ([
        'items-flex-start', 'items-centre', 'items-middle', 'items-fill',
        'justify-spacebetween', 'justify-spacearound', 'justify-spaceevenly',
        'self-fill', 'self-middle',
    ] as $class) {
        expect(TailwindParser::parse($class))->toBe([], "[$class] must not parse as a utility class");
    }

    // The aliases still work where they're intended to.
    expect(AlignItems::parse('centre'))->toBe(1);
    expect(JustifyContent::parse('space-between'))->toBe(3);
});
