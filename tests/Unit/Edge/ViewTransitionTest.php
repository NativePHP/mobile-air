<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Edge\Transition;

/**
 * Shared-element transitions — the native analogue of CSS view transitions.
 *
 * Identity is the element's `ref`, which already existed as a test-targeting
 * handle: two screens naming an element the same thing is what a shared
 * element is, so there is no second naming concept. Motion is shaped by the
 * optional `morph` / `morph-duration` / `morph-easing` props.
 *
 * All of it rides the fallback (string-key) wire path, so there is no PropKey
 * table change and no C-extension rebuild involved.
 */
beforeEach(function () {
    $this->precompiler = new NativeTagPrecompiler;
    NativeTagPrecompiler::setActive(true);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
});

// ── Identity ─────────────────────────────────────────────────────

it('carries ref as a prop as well as a node field', function () {
    $node = Column::make()->ref('album-3')->toArray(new CallbackRegistry);

    // The node-level field is what the test harness reads...
    expect($node['ref'])->toBe('album-3');
    // ...and the prop is what actually reaches the renderer, since no native
    // node model parses the node-level field.
    expect($node['props']['ref'])->toBe('album-3');
});

// The `ref="…"` attribute → Element::ref() wiring runs through the collector's
// protected applyCallbacks(); it is covered end-to-end by the demo app's
// HeroTransitionsTest (which renders real Blade and asserts props.ref), rather
// than by widening visibility here just to reach it.

it('emits no ref prop by default', function () {
    expect(Column::make()->toArray(new CallbackRegistry)['props'] ?? [])
        ->not->toHaveKey('ref');
});

it('no longer recognises the old transition-name attribute', function () {
    $element = Column::make();
    NativeElementCollector::applyStyle($element, ['transition-name' => 'album-3']);

    $props = $element->toArray(new CallbackRegistry)['props'] ?? [];
    expect($props)->not->toHaveKey('transition_name');
    expect($props)->not->toHaveKey('ref');
});

// ── Motion controls ──────────────────────────────────────────────

it('emits the morph style', function () {
    foreach (['frame', 'position', 'size', 'none'] as $mode) {
        $element = Column::make();
        NativeElementCollector::applyStyle($element, ['morph' => $mode]);

        expect($element->toArray(new CallbackRegistry)['props']['morph'])->toBe($mode);
    }
});

it('emits per-element timing', function () {
    $element = Column::make();
    NativeElementCollector::applyStyle($element, [
        'morph-duration' => '600',
        'morph-easing' => 'spring',
    ]);

    $props = $element->toArray(new CallbackRegistry)['props'];
    expect($props['morph_duration'])->toBe(600.0);
    expect($props['morph_easing'])->toBe('spring');
});

it('leaves timing unset so the shared view-transition pace applies', function () {
    $element = Column::make();
    NativeElementCollector::applyStyle($element, ['morph' => 'position']);

    $props = $element->toArray(new CallbackRegistry)['props'];
    expect($props)->not->toHaveKey('morph_duration');
    expect($props)->not->toHaveKey('morph_easing');
});

// ── Blade + directive plumbing ───────────────────────────────────

it('survives the blade precompiler as static attributes', function () {
    $result = ($this->precompiler)(
        '<native:column ref="album-3" morph="position" morph-easing="spring" />'
    );

    expect($result)->toContain("'ref' => 'album-3'");
    expect($result)->toContain("'morph' => 'position'");
    expect($result)->toContain("'morph-easing' => 'spring'");
});

it('compiles @navigate.viewTransition to the view_transition intent', function () {
    expect(($this->precompiler)("<native:column @navigate.viewTransition('/album/3') />"))
        ->toContain('view_transition');
});

it('lets back navigation carry the transition too', function () {
    expect(($this->precompiler)('<native:column @navigate.back.viewTransition />'))
        ->toContain('view_transition');
});

it('exposes ViewTransition on the Transition enum', function () {
    expect(Transition::ViewTransition->value)->toBe('view_transition');
    expect(Transition::from('view_transition'))->toBe(Transition::ViewTransition);
});
