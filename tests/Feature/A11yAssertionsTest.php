<?php

use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\PestExpectations;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\A11yBladeScreen;
use Tests\Fixtures\Edge\A11yScreen;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../Fixtures/views');
});

// ── assertAccessible ────────────────────────────────

it('passes a fully labelled screen', function () {
    Native::test(A11yScreen::class)
        ->set('accessible', true)
        ->assertAccessible();
});

it('fails an unlabelled screen with one violation per rule', function () {
    $screen = Native::test(A11yScreen::class);

    try {
        $screen->assertAccessible();
        $this->fail('assertAccessible() should have failed.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('icon-only <button> [leading_icon=trash] has no a11y-label')
            ->toContain('clickable <icon> [name=gear] has no a11y-label')
            ->toContain('clickable <image>')
            ->toContain('has no alt text')
            ->toContain('<pressable> has neither visible text nor an a11y-label')
            ->toContain('<text_input> has no label, placeholder, or a11y-label')
            ->toContain('<toggle> has no label or a11y-label');
    }
});

it('exposes violations for inspection and allow-listing', function () {
    $violations = Native::test(A11yScreen::class)->accessibilityViolations();

    expect($violations)->toHaveCount(6);

    expect(Native::test(A11yScreen::class)->set('accessible', true)->accessibilityViolations())
        ->toBe([]);
});

// ── HasA11y on the base Element ─────────────────────

it('serializes a11yLabel and a11yHint from any element via the base trait', function () {
    Native::test(A11yScreen::class)
        ->set('accessible', true)
        ->assertElement('icon', fn (array $n) => ($n['props']['a11y_label'] ?? null) === 'Settings')
        ->assertElement('image', fn (array $n) => ($n['props']['alt'] ?? null) === 'Tap target');
});

// ── Collector hydration of Blade a11y attributes ────

it('hydrates a11y-label and a11y-hint blade attributes for builtin elements', function () {
    Native::test(A11yBladeScreen::class)
        ->assertElement('pressable', fn (array $n) => ($n['props']['a11y_label'] ?? null) === 'Open settings'
            && ($n['props']['a11y_hint'] ?? null) === 'Opens the settings screen')
        ->assertElement('image', fn (array $n) => ($n['props']['alt'] ?? null) === 'Company logo')
        ->assertAccessible();
});

// ── Pest sugar ──────────────────────────────────────

it('composes with expect() via toBeAccessible', function () {
    PestExpectations::register();

    expect(Native::test(A11yScreen::class)->set('accessible', true))->toBeAccessible();
});
