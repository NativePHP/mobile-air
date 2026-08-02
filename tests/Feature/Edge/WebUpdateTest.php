<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\Web\WebScreenRunner;
use Tests\Fixtures\Edge\WebLazyScreen;
use Tests\Fixtures\Edge\WebProbeScreen;

beforeEach(function () {
    NativeRouter::clearRoutes();
    WebProbeScreen::$mounts = 0;
    WebLazyScreen::$mounts = 0;
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

/**
 * Boot a screen through the web GET path and parse the #edge-state blob
 * the client runtime reads (endpoint, snapshot, callbacks, lazy flag).
 */
function webBoot(string $class): array
{
    NativeRouter::register('/', $class);

    $page = WebScreenRunner::screen($class);
    $html = $page instanceof \Symfony\Component\HttpFoundation\Response
        ? (string) $page->getContent()
        : (string) $page;

    preg_match('/<script type="application\/json" id="edge-state">(.+?)<\/script>/s', $html, $m);

    return ['html' => $html, 'state' => json_decode($m[1] ?? 'null', true)];
}

/** POST a UI event to the update endpoint the way edge-web.js does. */
function webUpdate(array $state, array $event, ?array $snapshot = null)
{
    return test()->postJson($state['endpoint'], [
        'component' => $state['component'],
        'uri' => $state['uri'],
        'params' => $state['params'],
        'snapshot' => $snapshot ?? $state['snapshot'],
        'preview' => null,
        'event' => $event,
    ]);
}

/** The content-addressed callback id for an expression, from the sealed wire map. */
function webCallbackId(array $snapshot, string $expression): int
{
    return (int) $snapshot['data']['callbacks'][$expression];
}

it('boots via GET, mounts exactly once, and binds presses in the HTML', function () {
    $boot = webBoot(WebProbeScreen::class);

    expect(WebProbeScreen::$mounts)->toBe(1)
        ->and($boot['html'])->toContain('Count: 0')
        ->and($boot['html'])->toContain(
            'data-edge-press="'.webCallbackId($boot['state']['snapshot'], 'increment').'"'
        );
});

it('dispatches a press without re-running mount()', function () {
    $boot = webBoot(WebProbeScreen::class);

    $res = webUpdate($boot['state'], [
        'type' => 'press',
        'callback_id' => webCallbackId($boot['state']['snapshot'], 'increment'),
    ])->assertOk();

    expect(WebProbeScreen::$mounts)->toBe(1)
        ->and($res->json('html'))->toContain('Count: 1')
        ->and($res->json('html'))->toContain('Doubled: 2');
});

it('round-trips state through the snapshot across sequential updates', function () {
    $boot = webBoot(WebProbeScreen::class);
    $id = webCallbackId($boot['state']['snapshot'], 'increment');

    $first = webUpdate($boot['state'], ['type' => 'press', 'callback_id' => $id])->assertOk();
    $second = webUpdate($boot['state'], ['type' => 'press', 'callback_id' => $id], $first->json('snapshot'))->assertOk();

    expect($second->json('html'))->toContain('Count: 2')
        ->and($second->json('html'))->toContain('Doubled: 4')
        ->and(WebProbeScreen::$mounts)->toBe(1);
});

it('runs #[Poll] methods on a poll tick without re-running mount()', function () {
    $boot = webBoot(WebProbeScreen::class);

    $res = webUpdate($boot['state'], ['type' => 'poll'])->assertOk();

    expect(WebProbeScreen::$mounts)->toBe(1)
        ->and($res->json('html'))->toContain('Ticks: 1');
});

it('serves a #[Lazy] placeholder unmounted, then mounts exactly once on the lazy boot event', function () {
    $boot = webBoot(WebLazyScreen::class);

    expect(WebLazyScreen::$mounts)->toBe(0)
        ->and($boot['state']['lazy'])->toBeTrue()
        ->and($boot['html'])->not->toContain('mounted-value');

    $lazy = webUpdate($boot['state'], ['type' => 'lazy'])->assertOk();

    expect(WebLazyScreen::$mounts)->toBe(1)
        ->and($lazy->json('html'))->toContain('Value: mounted-value');

    // Interactions after the lazy boot rehydrate from the snapshot:
    // mount() stays at one and its state survives the round trip.
    $snap = $lazy->json('snapshot');
    $next = webUpdate($boot['state'], [
        'type' => 'press',
        'callback_id' => webCallbackId($snap, 'increment'),
    ], $snap)->assertOk();

    expect(WebLazyScreen::$mounts)->toBe(1)
        ->and($next->json('html'))->toContain('Count: 1')
        ->and($next->json('html'))->toContain('Value: mounted-value');
});

it('rejects a tampered snapshot with 419', function () {
    $boot = webBoot(WebProbeScreen::class);
    $snapshot = $boot['state']['snapshot'];
    $snapshot['data']['props']['count'] = 999;

    webUpdate($boot['state'], ['type' => 'press', 'callback_id' => 1], $snapshot)
        ->assertStatus(419);
});
