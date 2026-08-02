<?php

use Native\Mobile\Edge\Web\Renderer\HtmlRendererRegistry;
use Native\Mobile\Edge\Web\Renderer\WebRenderer;

afterEach(fn () => HtmlRendererRegistry::reset());

it('renders a plugin-registered type through its registered renderer', function () {
    HtmlRendererRegistry::register('rating_stars', fn (array $node, array $ctx): string => '<div'.WebRenderer::idAttr($node).' class="'.WebRenderer::cls($node, 'flex flex-row').'" data-stars="'.((int) ($node['props']['count'] ?? 0)).'">'
            .WebRenderer::children($node, $ctx)
        .'</div>');

    $html = WebRenderer::render([
        'id' => 7,
        'type' => 'rating_stars',
        'props' => ['count' => 4],
        'children' => [
            ['id' => 8, 'type' => 'text', 'props' => ['text' => 'inner'], 'children' => []],
        ],
    ]);

    expect($html)->toContain('data-stars="4"')
        ->and($html)->toContain('data-edge-id="7"')
        ->and($html)->toContain('inner');
});

it('lets a registration override a built-in type', function () {
    HtmlRendererRegistry::register('badge', fn (array $node, array $ctx): string => '<span data-custom-badge>overridden</span>');

    $html = WebRenderer::render([
        'id' => 1,
        'type' => 'badge',
        'props' => ['label' => 'ignored'],
        'children' => [],
    ]);

    expect($html)->toBe('<span data-custom-badge>overridden</span>');
});

it('keeps the built-in fallback for unregistered unknown types', function () {
    $html = WebRenderer::render([
        'id' => 2,
        'type' => 'totally_unknown_container',
        'props' => [],
        'children' => [
            ['id' => 3, 'type' => 'text', 'props' => ['text' => 'still visible'], 'children' => []],
        ],
    ]);

    expect($html)->toContain('still visible');
});
