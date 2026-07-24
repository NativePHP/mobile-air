<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function () {
    TailwindParser::clearCache();
});

/** Resolve the `anchor` prop an element ends up with for the given attrs. */
function anchorPropFor(array $attrs): ?int
{
    $element = Column::make();
    NativeElementCollector::applyElementProps($element, $attrs);

    return $element->toArray(new CallbackRegistry)['props']['anchor'] ?? null;
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
