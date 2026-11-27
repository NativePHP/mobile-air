<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;

beforeEach(function () {
    NativeElementCollector::reset();
    NativeElementCollector::stopCapturingAttributes();
});

afterEach(function () {
    NativeElementCollector::reset();
    NativeElementCollector::stopCapturingAttributes();
});

function capturedTree(string $type, array $attrs): array
{
    NativeElementCollector::leaf($type, $attrs);

    return NativeElementCollector::collect()->toArray(new CallbackRegistry);
}

it('lifts a registered attribute into a prop and strips it from the element', function () {
    NativeElementCollector::captureAttribute('track', 'analytics_id');

    $tree = capturedTree('text', ['text' => 'Sign up', 'track' => 'signup-cta']);

    expect($tree['props']['analytics_id'] ?? null)->toBe('signup-cta')
        ->and($tree['props'])->not->toHaveKey('track');
});

it('ignores unregistered attributes entirely', function () {
    $tree = capturedTree('text', ['text' => 'Sign up', 'track' => 'signup-cta']);

    expect($tree['props'] ?? [])->not->toHaveKey('analytics_id');
});

it('captures the raw class string without disturbing Tailwind parsing', function () {
    NativeElementCollector::captureAttribute('class', 'raw_class');

    $tree = capturedTree('text', ['text' => 'x', 'class' => 'flex-1 p-4']);

    // The raw string is preserved AND the classes still parsed to layout.
    expect($tree['props']['raw_class'] ?? null)->toBe('flex-1 p-4')
        ->and($tree['layout']['flex_grow'] ?? $tree['layout']['padding'] ?? null)->not->toBeNull();
});

it('strips but does not capture empty string values', function () {
    NativeElementCollector::captureAttribute('track', 'analytics_id');

    $tree = capturedTree('text', ['text' => 'x', 'track' => '']);

    expect($tree['props'] ?? [])->not->toHaveKey('analytics_id')
        ->and($tree['props'] ?? [])->not->toHaveKey('track');
});

// streamView() writes nodes straight to the C buffer through nphp_node_*.
// The extension isn't loaded under test, so these stand-ins record what
// the streaming path would have sent.
require_once __DIR__.'/../../Support/global-streaming-stubs.php';

function streamedProps(string $type, array $attrs, bool $open = false): array
{
    $GLOBALS['nphpStreamed'] = [];
    NativeElementCollector::setCallbacks(new CallbackRegistry);
    NativeElementCollector::setStreaming(true);

    try {
        if ($open) {
            NativeElementCollector::open($type, $attrs);
            NativeElementCollector::close();
        } else {
            NativeElementCollector::leaf($type, $attrs);
        }
    } finally {
        NativeElementCollector::setStreaming(false);
    }

    return $GLOBALS['nphpStreamed'][0][3];
}

it('lifts a registered attribute into a prop on a streamed plugin element', function () {
    NativeElementCollector::captureAttribute('revalidate', 'revalidate');

    $props = streamedProps('image', ['src' => 'https://example.com/a.jpg', 'revalidate' => true]);

    expect($props['revalidate'] ?? null)->toBeTrue()
        ->and($props['src'] ?? null)->toBe('https://example.com/a.jpg');
});

it('lifts a registered attribute into a prop on a streamed built-in container', function () {
    NativeElementCollector::captureAttribute('track', 'analytics_id');

    $props = streamedProps('column', ['class' => 'p-4', 'track' => 'signup-cta'], open: true);

    expect($props['analytics_id'] ?? null)->toBe('signup-cta')
        ->and($props)->not->toHaveKey('track');
});

it('captures the raw class string on a streamed element', function () {
    NativeElementCollector::captureAttribute('class', 'raw_class');

    expect(streamedProps('text', ['text' => 'x', 'class' => 'flex-1 p-4'])['raw_class'] ?? null)->toBe('flex-1 p-4')
        ->and(streamedProps('row', ['class' => 'gap-2'])['raw_class'] ?? null)->toBe('gap-2');
});

it('strips but does not capture empty string values on a streamed element', function () {
    NativeElementCollector::captureAttribute('track', 'analytics_id');

    $props = streamedProps('text', ['text' => 'x', 'track' => '']);

    expect($props)->not->toHaveKey('analytics_id')
        ->and($props)->not->toHaveKey('track');
});

it('streams the same captured props the element tree carries', function () {
    NativeElementCollector::captureAttribute('track', 'analytics_id');
    $attrs = ['text' => 'Sign up', 'track' => 'signup-cta'];

    $tree = capturedTree('text', $attrs);
    NativeElementCollector::reset();

    expect(streamedProps('text', $attrs)['analytics_id'] ?? null)->toBe($tree['props']['analytics_id']);
});
