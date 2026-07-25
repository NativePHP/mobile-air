<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function () {
    TailwindParser::clearCache();
});

/** The props an element ends up with after the collector applies the attrs. */
function positionPropsFor(array $attrs): array
{
    $element = Column::make();
    NativeElementCollector::applyElementProps($element, $attrs);

    return $element->toArray(new CallbackRegistry)['props'] ?? [];
}

/** Just the `anchor` prop, or null. */
function anchorPropFor(array $attrs): ?int
{
    return positionPropsFor($attrs)['anchor'] ?? null;
}

// ── Attribute-based position (parity with the utility classes) ──────────

it('reads the bare absolute attribute like the class', function () {
    expect(NativeElementCollector::buildLayoutArray(['absolute' => true]))
        ->toBe(['position_type' => 1]);

    expect(NativeElementCollector::buildLayoutArray(['relative' => true]))
        ->toBe(['position_type' => 0]);
});

it('reads position="absolute" / position="relative"', function () {
    expect(NativeElementCollector::buildLayoutArray(['position' => 'absolute']))
        ->toBe(['position_type' => 1]);

    expect(NativeElementCollector::buildLayoutArray(['position' => 'relative']))
        ->toBe(['position_type' => 0]);
});

it('reads bare top/right/bottom/left offset attributes', function () {
    expect(NativeElementCollector::buildLayoutArray(['top' => 0, 'right' => 0]))
        ->toBe(['position' => [0.0, 0.0, 0.0, 0.0]]);

    expect(NativeElementCollector::buildLayoutArray(['top' => 8, 'left' => 12]))
        ->toBe(['position' => [8.0, 0.0, 0.0, 12.0]]);
});

it('lets the explicit positionType/positionTop win over the short spelling', function () {
    // positionTop (from `top-4`) takes precedence over a bare `top`.
    expect(NativeElementCollector::buildLayoutArray(['positionTop' => 4, 'top' => 99]))
        ->toBe(['position' => [4.0, 0.0, 0.0, 0.0]]);

    expect(NativeElementCollector::buildLayoutArray(['positionType' => 1, 'absolute' => true]))
        ->toBe(['position_type' => 1]);
});

it('composes absolute + offsets from attributes exactly like the classes', function () {
    $fromAttrs = NativeElementCollector::buildLayoutArray(['absolute' => true, 'top' => 0, 'right' => 0]);

    $fromClasses = NativeElementCollector::buildLayoutArray(
        array_merge(TailwindParser::parse('absolute top-0 right-0'), [])
    );

    expect($fromAttrs)->toBe($fromClasses)
        ->and($fromAttrs)->toBe(['position_type' => 1, 'position' => [0.0, 0.0, 0.0, 0.0]]);
});

// ── Stack anchor ────────────────────────────────────────────────────────
// The anchor rides the props blob (not the fixed layout struct), so it
// surfaces on the element's serialized `props`, not its `layout`.

it('never lands in the layout struct', function () {
    // The C packer would drop an unknown layout key, so anchor must not be there.
    expect(NativeElementCollector::buildLayoutArray(['anchor' => 'top-right']))->toBe([]);
});

it('resolves every anchor name to its wire enum as a prop', function (string $name, int $value) {
    expect(anchorPropFor(['anchor' => $name]))->toBe($value);
})->with([
    ['center', 0],
    ['top-left', 1],
    ['top', 2],
    ['top-center', 2],
    ['top-right', 3],
    ['left', 4],
    ['center-left', 4],
    ['right', 5],
    ['center-right', 5],
    ['bottom-left', 6],
    ['bottom', 7],
    ['bottom-center', 7],
    ['bottom-right', 8],
]);

it('emits no anchor prop for an unknown name', function () {
    expect(anchorPropFor(['anchor' => 'nowhere']))->toBeNull();
});

it('parses the anchor-* utility class to the anchor name', function () {
    expect(TailwindParser::parse('anchor-top-right'))->toBe(['anchor' => 'top-right']);
    expect(TailwindParser::parse('anchor-center'))->toBe(['anchor' => 'center']);
});

it('resolves the anchor class end to end into the prop enum', function () {
    expect(anchorPropFor(TailwindParser::parse('anchor-bottom-right')))->toBe(8);
});

// ── Origin (the point ON the child that aligns to the parent's anchor) ───

it('resolves the origin attribute to its own prop', function () {
    expect(positionPropsFor(['origin' => 'top-left'])['origin'] ?? null)->toBe(1);
    expect(positionPropsFor(['origin' => 'center'])['origin'] ?? null)->toBe(0);
});

it('parses the origin-* utility class', function () {
    expect(TailwindParser::parse('origin-bottom-right'))->toBe(['origin' => 'bottom-right']);
});

it('carries anchor and origin independently on the same element', function () {
    // A badge whose top-left (origin) hooks onto the parent's top-right (anchor).
    expect(positionPropsFor(['anchor' => 'top-right', 'origin' => 'top-left']))
        ->toMatchArray(['anchor' => 3, 'origin' => 1]);

    // Mix attribute + class.
    $attrs = array_merge(TailwindParser::parse('origin-center'), ['anchor' => 'bottom-left']);
    expect(positionPropsFor($attrs))->toMatchArray(['anchor' => 6, 'origin' => 0]);
});

it('emits no origin prop for an unknown name', function () {
    expect(positionPropsFor(['origin' => 'nowhere']))->not->toHaveKey('origin');
});
