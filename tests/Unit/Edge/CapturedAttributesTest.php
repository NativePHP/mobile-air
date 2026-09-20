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
