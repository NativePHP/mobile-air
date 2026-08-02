<?php

use Native\Mobile\Edge\Web\WebRenderer;

// Press callbacks arrive on the wire two ways: most elements carry a
// node-level `on_press`, but some plugin elements (notably mobile-ui
// `button`) ride it in the props dict. The renderer must bind both —
// missing the props channel shipped buttons with no click binding.

it('binds a node-level on_press as data-edge-press', function () {
    $html = WebRenderer::render([
        'id' => 1,
        'type' => 'text',
        'on_press' => 123,
        'props' => ['text' => 'tap me'],
        'children' => [],
    ]);

    expect($html)->toContain('data-edge-press="123"');
});

it('binds a props-channel on_press (plugin buttons) as data-edge-press', function () {
    $html = WebRenderer::render([
        'id' => 2,
        'type' => 'button',
        'props' => ['label' => '+1', 'on_press' => 456],
        'children' => [],
    ]);

    expect($html)->toContain('data-edge-press="456"');
});

it('makes a pressable non-button focusable and announced as a button', function () {
    $html = WebRenderer::render([
        'id' => 3,
        'type' => 'text',
        'on_press' => 789,
        'props' => ['text' => 'pressable text'],
        'children' => [],
    ]);

    expect($html)->toContain('role="button"')
        ->and($html)->toContain('tabindex="0"');
});
