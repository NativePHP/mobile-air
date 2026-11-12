<?php

use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\ServerRegionScreen;

/**
 * Blade `@server` → collector → element tree, for the region primitive
 * described in docs/ASGARD-PROTOCOL.md §3. No transport is involved:
 * fragments arrive through `ServerRegion::applyFragment()`, which is the
 * seam a later workstream feeds from the network.
 */
beforeEach(function () {
    NativeElementCollector::reset();
    ServerRegion::flush();
    NativeTagPrecompiler::setActive(true);

    $testViewPath = __DIR__.'/views';
    if (! is_dir($testViewPath)) {
        mkdir($testViewPath, 0755, true);
    }

    app('view')->addLocation($testViewPath);
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
    NativeElementCollector::reset();
    ServerRegion::flush();

    $testViewPath = __DIR__.'/views';
    if (is_dir($testViewPath)) {
        foreach (glob($testViewPath.'/*.php') as $file) {
            unlink($file);
        }
    }
});

/** Write a throwaway blade view and render it into an element tree. */
function renderNativeView(string $name, string $template): Element
{
    file_put_contents(__DIR__."/views/{$name}.blade.php", $template);

    // Stand in for NativeComponent, which is what marks a frame boundary
    // in a real render.
    ServerRegion::beginFrame();
    NativeElementCollector::reset();
    view($name)->render();

    return NativeElementCollector::collect();
}

it('renders a server region with its placeholder as children', function () {
    $tree = renderNativeView('asgard-placeholder', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>Loading sales…</native:text>
            @endserver
        </native:column>
        BLADE)->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(1);

    $region = $tree['children'][0];

    // On the wire a region is a bare column, identified only by its key.
    expect($region['type'])->toBe('column');
    expect($region['id'])->toBe(Element::fnv1a32('/sales-summary'));
    expect($region)->not->toHaveKey('props');
    expect($region['children'])->toHaveCount(1);
    expect($region['children'][0]['type'])->toBe('text');
    expect($region['children'][0]['props']['text'])->toBe('Loading sales…');
});

it('replaces the placeholder with an applied fragment', function () {
    $template = <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>Loading sales…</native:text>
            @endserver
        </native:column>
        BLADE;

    $before = renderNativeView('asgard-replace', $template)->toArray(new CallbackRegistry);
    expect($before['children'][0]['children'][0]['props']['text'])->toBe('Loading sales…');

    $applied = ServerRegion::applyFragment('sales-summary', [
        'type' => 'column',
        'key' => 'summary',
        'children' => [
            ['type' => 'text', 'key' => 'total', 'props' => ['text' => 'Revenue: £12,400']],
        ],
    ]);

    expect($applied)->toBeTrue();

    $after = renderNativeView('asgard-replace', $template)->toArray(new CallbackRegistry);
    $region = $after['children'][0];

    expect($region['type'])->toBe('column');
    expect($region['id'])->toBe(Element::fnv1a32('/sales-summary'));
    expect($region['children'])->toHaveCount(1);
    expect($region['children'][0]['type'])->toBe('column');
    expect($region['children'][0]['children'][0]['props']['text'])->toBe('Revenue: £12,400');

    // The placeholder is gone, not merely pushed down.
    expect(json_encode($region))->not->toContain('Loading sales');
});

it('derives fragment node ids inside the region key path, not at the root', function () {
    ServerRegion::applyFragment('sales-summary', [
        'type' => 'column',
        'key' => 'summary',
        'children' => [
            ['type' => 'text', 'key' => 'total', 'props' => ['text' => 'Revenue']],
        ],
    ]);

    $tree = renderNativeView('asgard-keys', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>Loading…</native:text>
            @endserver
        </native:column>
        BLADE)->toArray(new CallbackRegistry);

    $region = $tree['children'][0];
    $fragmentRoot = $region['children'][0];
    $total = $fragmentRoot['children'][0];

    expect($region['id'])->toBe(Element::fnv1a32('/sales-summary'));
    expect($fragmentRoot['id'])->toBe(Element::fnv1a32('/sales-summary/summary'));
    expect($total['id'])->toBe(Element::fnv1a32('/sales-summary/summary/total'));

    // The same relative keys at the root would be different nodes entirely.
    expect($fragmentRoot['id'])->not->toBe(Element::fnv1a32('/summary'));
    expect($total['id'])->not->toBe(Element::fnv1a32('/summary/total'));
});

it('derives fragment callback ids in the region scope, not the screen scope', function () {
    ServerRegion::applyFragment('sales-summary', [
        'type' => 'column',
        'key' => 'summary',
        'on_press' => ['call' => 'drillDown', 'args' => ['eu']],
    ]);

    $tree = renderNativeView('asgard-callbacks', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>Loading…</native:text>
            @endserver
        </native:column>
        BLADE);

    $screen = new CallbackRegistry;
    $rendered = $tree->toArray($screen);

    $fragmentRoot = $rendered['children'][0]['children'][0];
    $regionScoped = ServerRegion::registryFor('sales-summary');

    expect($regionScoped->scope())->toBe('server:sales-summary');
    expect(array_values($regionScoped->expressions()))->toBe([$fragmentRoot['on_press']]);

    // What the id resolves to carries its target, so it can only be
    // dispatched the way the server asked.
    expect($regionScoped->resolve($fragmentRoot['on_press']))
        ->toBe(['method' => 'drillDown', 'args' => ['eu'], 'target' => 'server']);

    // The screen never sees it, and the id is not the one an unscoped
    // registration of the same expression would have produced.
    expect($screen->expressions())->toBe([]);
    expect($screen->resolve($fragmentRoot['on_press']))->toBeNull();
    $expression = array_key_first($regionScoped->expressions());
    expect($fragmentRoot['on_press'])->not->toBe((new CallbackRegistry)->register($expression));
});

it('leaves placeholder callbacks in the screen scope', function () {
    $tree = renderNativeView('asgard-placeholder-callbacks', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text @tap="retry">Retry</native:text>
            @endserver
        </native:column>
        BLADE);

    $screen = new CallbackRegistry;
    $rendered = $tree->toArray($screen);

    $placeholder = $rendered['children'][0]['children'][0];

    expect($screen->resolve($placeholder['on_press']))->toBe(['method' => 'retry', 'args' => []]);
});

it('renders a screen with an unresolved region and everything around it', function () {
    $tree = renderNativeView('asgard-degraded', <<<'BLADE'
        <native:column fill>
            <native:text>Dashboard</native:text>
            @server('sales-summary')
                <native:text>Loading sales…</native:text>
            @endserver
            <native:text @tap="refresh">Refresh</native:text>
        </native:column>
        BLADE);

    $registry = new CallbackRegistry;
    $rendered = $tree->toArray($registry);

    expect($rendered['children'])->toHaveCount(3);
    expect($rendered['children'][0]['props']['text'])->toBe('Dashboard');
    expect($rendered['children'][1]['id'])->toBe(Element::fnv1a32('/sales-summary'));
    expect($rendered['children'][1]['children'][0]['props']['text'])->toBe('Loading sales…');
    expect($rendered['children'][2]['props']['text'])->toBe('Refresh');
    expect($registry->resolve($rendered['children'][2]['on_press']))
        ->toBe(['method' => 'refresh', 'args' => []]);

    expect(ServerRegion::hasFragment('sales-summary'))->toBeFalse();
});

it('records the regions that rendered in the frame', function () {
    ServerRegion::applyFragment('sales-summary', ['type' => 'text', 'props' => ['text' => 'ok']]);

    renderNativeView('asgard-record', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>Loading…</native:text>
            @endserver
            @server('recent-orders')
                <native:text>Loading…</native:text>
            @endserver
        </native:column>
        BLADE);

    $rendered = ServerRegion::rendered();

    expect(array_keys($rendered))->toBe(['sales-summary', 'recent-orders']);
    expect($rendered['sales-summary']['key_path'])->toBe('/sales-summary');
    expect($rendered['sales-summary']['has_fragment'])->toBeTrue();
    expect($rendered['recent-orders']['has_fragment'])->toBeFalse();
});

it('nests a region key path inside its enclosing region', function () {
    $tree = renderNativeView('asgard-nested', <<<'BLADE'
        <native:column fill>
            @server('outer')
                @server('inner')
                    <native:text>Loading…</native:text>
                @endserver
            @endserver
        </native:column>
        BLADE)->toArray(new CallbackRegistry);

    $outer = $tree['children'][0];
    $inner = $outer['children'][0];

    expect($outer['id'])->toBe(Element::fnv1a32('/outer'));
    expect($inner['id'])->toBe(Element::fnv1a32('/outer/inner'));
    expect(ServerRegion::rendered()['inner']['key_path'])->toBe('/outer/inner');
});

it('throws on a duplicate region name while debugging', function () {
    config(['app.debug' => true]);

    renderNativeView('asgard-duplicate-debug', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>One</native:text>
            @endserver
            @server('sales-summary')
                <native:text>Two</native:text>
            @endserver
        </native:column>
        BLADE);
})->throws(\Illuminate\View\ViewException::class, "Duplicate Asgard region 'sales-summary'");

it('suffixes a duplicate region name in production', function () {
    config(['app.debug' => false]);

    ServerRegion::applyFragment('sales-summary', ['type' => 'text', 'props' => ['text' => 'Fragment']]);

    $tree = renderNativeView('asgard-duplicate-prod', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>One</native:text>
            @endserver
            @server('sales-summary')
                <native:text>Two</native:text>
            @endserver
        </native:column>
        BLADE)->toArray(new CallbackRegistry);

    // First one keeps the name and therefore the fragment; the duplicate
    // resolves to nothing and keeps its placeholder.
    expect($tree['children'][0]['id'])->toBe(Element::fnv1a32('/sales-summary'));
    expect($tree['children'][0]['children'][0]['props']['text'])->toBe('Fragment');

    expect($tree['children'][1]['id'])->toBe(Element::fnv1a32('/sales-summary#2'));
    expect($tree['children'][1]['children'][0]['props']['text'])->toBe('Two');
});

it('rejects a fragment naming an element type this build does not have', function () {
    config(['app.debug' => false]);

    $applied = ServerRegion::applyFragment('sales-summary', [
        'type' => 'column',
        'children' => [['type' => 'holographic_chart', 'props' => []]],
    ]);

    expect($applied)->toBeFalse();
    expect(ServerRegion::hasFragment('sales-summary'))->toBeFalse();

    $tree = renderNativeView('asgard-unknown-type', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>Loading…</native:text>
            @endserver
        </native:column>
        BLADE)->toArray(new CallbackRegistry);

    expect($tree['children'][0]['children'][0]['props']['text'])->toBe('Loading…');
});

it('refuses to let a fragment place screen chrome', function () {
    config(['app.debug' => false]);

    $applied = ServerRegion::applyFragment('sales-summary', [
        'type' => 'column',
        'children' => [['type' => 'top_bar', 'props' => []]],
    ]);

    expect($applied)->toBeFalse();
});

it('drops compiler-internal attribute spellings from a fragment props bag', function () {
    ServerRegion::applyFragment('sales-summary', [
        'type' => 'text',
        'props' => [
            'text' => 'Total',
            '_press' => 'deleteAccount',
            '_navigate' => ['route' => '/admin'],
            'native-key' => 'spoofed',
        ],
        'key' => 'total',
    ]);

    $tree = renderNativeView('asgard-attr-strip', <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>Loading…</native:text>
            @endserver
        </native:column>
        BLADE);

    $screen = new CallbackRegistry;
    $node = $tree->toArray($screen)['children'][0]['children'][0];

    expect($node['props']['text'])->toBe('Total');
    expect($node)->not->toHaveKey('on_press');
    expect($node['id'])->toBe(Element::fnv1a32('/sales-summary/total'));
    expect(ServerRegion::registryFor('sales-summary')->lookup('deleteAccount'))->toBeNull();
});

it('goes back to the placeholder when a fragment is cleared', function () {
    $template = <<<'BLADE'
        <native:column fill>
            @server('sales-summary')
                <native:text>Loading…</native:text>
            @endserver
        </native:column>
        BLADE;

    ServerRegion::applyFragment('sales-summary', ['type' => 'text', 'props' => ['text' => 'Fragment']]);

    $withFragment = renderNativeView('asgard-clear', $template)->toArray(new CallbackRegistry);
    expect($withFragment['children'][0]['children'][0]['props']['text'])->toBe('Fragment');

    ServerRegion::clearFragment('sales-summary');

    $cleared = renderNativeView('asgard-clear', $template)->toArray(new CallbackRegistry);
    expect($cleared['children'][0]['children'][0]['props']['text'])->toBe('Loading…');
});

// ── Through a real screen render ────────────────────

it('renders a screen whose region never resolves', function () {
    Native::test(ServerRegionScreen::class)
        ->assertElement('column', fn (array $node) => $node['id'] === Element::fnv1a32('/sales-summary'))
        ->assertSee('Dashboard')
        ->assertSee('Loading sales…')
        ->tap('refresh')
        ->assertSet('refreshes', 1)
        ->assertSee('Refreshed 1')
        ->assertSee('Loading sales…');

    expect(ServerRegion::rendered()['sales-summary']['has_fragment'])->toBeFalse();
});

it('paints a fragment on the next frame of a live screen', function () {
    $screen = Native::test(ServerRegionScreen::class)->assertSee('Loading sales…');

    // Fragments belong to the component that renders the region.
    $screen->instance()->receiveFragment('sales-summary', [
        'type' => 'text',
        'key' => 'total',
        'props' => ['text' => 'Revenue: £12,400'],
    ]);

    $screen->tap('refresh')
        ->assertSee('Revenue: £12,400')
        ->assertDontSee('Loading sales…');
});

it('reports no regions for a screen that has none', function () {
    Native::test(ServerRegionScreen::class);
    expect(ServerRegion::rendered())->toHaveKey('sales-summary');

    Native::test(CounterScreen::class);
    expect(ServerRegion::rendered())->toBe([]);
});

it('names a region from a runtime expression and styles it from extra attrs', function () {
    $tree = renderNativeView('asgard-dynamic-name', <<<'BLADE'
        @php($slot = 'sales-summary')
        <native:column fill>
            @server($slot, ['class' => 'p-4'])
                <native:text>Loading…</native:text>
            @endserver
        </native:column>
        BLADE)->toArray(new CallbackRegistry);

    $region = $tree['children'][0];

    expect($region['type'])->toBe('column');
    expect($region['id'])->toBe(Element::fnv1a32('/sales-summary'));
    expect($region['layout']['padding'])->toBe(16.0);
});

// ── What reaches native (§12 q5) ────────────────────

it('keeps server_region as the PHP identity while sending a column', function () {
    $region = \Native\Mobile\Edge\Elements\ServerRegion::make('sales-summary');

    expect($region->getType())->toBe('server_region');
    expect($region->wireType())->toBe('column');
    expect(\Native\Mobile\Edge\ElementRegistry::has('server_region'))->toBeTrue();

    $node = $region->toArray(new CallbackRegistry);

    expect($node['type'])->toBe('column');
    expect($node['id'])->toBe(Element::fnv1a32('/sales-summary'));
    expect($node)->not->toHaveKeys(['props', 'layout', 'style']);
});

it('still refuses a fragment that opens a region of its own', function () {
    config(['app.debug' => false]);

    expect(ServerRegion::applyFragment('sales-summary', [
        'type' => 'column',
        'children' => [['type' => 'server_region', 'props' => ['region' => 'x']]],
    ]))->toBeFalse();
});

it('streams a region as a bare keyed column with the fragment under its path', function () {
    require_once __DIR__.'/../../Support/streaming-writer.php';

    ServerRegion::applyFragment('sales-summary', [
        'type' => 'text',
        'key' => 'total',
        'props' => ['text' => 'Revenue'],
    ]);

    file_put_contents(__DIR__.'/views/asgard-stream.blade.php', <<<'BLADE'
        @server('sales-summary')
            <native:text>Loading…</native:text>
        @endserver
        BLADE);

    $GLOBALS['nphp_stream'] = [];
    ServerRegion::beginFrame();
    NativeElementCollector::reset();
    NativeElementCollector::setCallbacks(new CallbackRegistry);
    NativeElementCollector::setStreaming(true);

    try {
        view('asgard-stream')->render();
    } finally {
        NativeElementCollector::setStreaming(false);
    }

    $calls = $GLOBALS['nphp_stream'];

    expect($calls[0])->toBe(['open', 'column', null, null, null, Element::fnv1a32('/sales-summary')]);
    expect($calls[1][0])->toBe('leaf');
    expect($calls[1][1])->toBe('text');
    expect($calls[1][4]['text'])->toBe('Revenue');
    expect($calls[1][5])->toBe(Element::fnv1a32('/sales-summary/total'));
    expect($calls[2])->toBe(['close']);
    expect(json_encode($calls))->not->toContain('server_region');
});
