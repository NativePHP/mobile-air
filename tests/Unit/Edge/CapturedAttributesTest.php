<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;

beforeEach(function () {
    NativeElementCollector::reset();
    NativeElementCollector::stopCapturingAttributes();
    NativeElementCollector::stopTransformingAttributes();
});

afterEach(function () {
    NativeElementCollector::reset();
    NativeElementCollector::stopCapturingAttributes();
    NativeElementCollector::stopTransformingAttributes();
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

it('runs named raw attribute transformers before Tailwind parsing', function () {
    NativeElementCollector::captureAttribute('debug-source', 'debug_source');
    NativeElementCollector::captureAttribute('class', 'raw_class');
    NativeElementCollector::transformAttributes('test.override', function (string $type, array $attrs): array {
        expect($type)->toBe('text');
        $attrs['class'] = 'p-8';

        return $attrs;
    });

    $tree = capturedTree('text', [
        'text' => 'x',
        'class' => 'p-2',
        'debug-source' => 'native/home.blade.php:10',
    ]);

    expect($tree['props']['raw_class'])->toBe('p-8')
        ->and($tree['props']['debug_source'])->toBe('native/home.blade.php:10')
        ->and($tree['layout']['padding'])->toBe(32.0);
});

it('isolates rendering from attribute transformer failures', function () {
    NativeElementCollector::transformAttributes('test.failure', function (): never {
        throw new RuntimeException('tooling failure');
    });

    $tree = capturedTree('text', ['text' => 'still renders']);

    expect($tree['props']['text'])->toBe('still renders');
});

it('never lets captured metadata override an element-resolved prop', function () {
    NativeElementCollector::captureAttribute('debug-text', 'text');

    $tree = capturedTree('text', ['text' => 'element value', 'debug-text' => 'captured value']);

    // Collision contract (see captureAttribute): rendering wins, uniformly
    // across the builtin, plugin-element, and streaming paths — capturing a
    // colliding name can never corrupt what the user sees.
    expect($tree['props']['text'])->toBe('element value');
});
