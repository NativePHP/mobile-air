<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Http\Bridge\BridgeDispatcher;
use Native\Mobile\Runtime;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;
use Tests\Support\Bridge;

beforeEach(function () {
    $this->globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST];
    $this->errorLog = ini_get('error_log');
    $this->log = tempnam(sys_get_temp_dir(), 'native-route-lane-log-');
    ini_set('error_log', $this->log);

    unset(app()[NativeRouteFallback::class]);
    NativeRouter::clearRoutes();
    Runtime::boot($this->app);

    app()->instance('native.posted-events', new ArrayObject);

    Route::native('/', CounterScreen::class);
    Route::native('/detail/{id}', DetailScreen::class);
});

afterEach(function () {
    [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST] = $this->globals;
    ini_set('error_log', $this->errorLog);
    @unlink($this->log);

    NativeRouter::clearRoutes();
});

/**
 * Stand in for the extension of a device, which no test loads. It keeps
 * what was posted, and takes it unless a test said the queue refuses.
 */
function nativephp_post_event(string $event, array|string $payload = [], int $callbackId = 0, int $nodeId = 0): bool
{
    app('native.posted-events')[] = ['event' => $event, 'payload' => $payload];

    return ! app()->bound('native.refuses-events');
}

function postedEvents(): array
{
    return app('native.posted-events')->getArrayCopy();
}

/** Ask for a page the way the php web view inside a native screen does. */
function fromWebView(string $uri): array
{
    return Bridge::parse(BridgeDispatcher::dispatch('android', 'webview', 'GET', $uri, '/native.php'));
}

it('hands a native route asked for by the web view to the screen on show', function () {
    $response = fromWebView('/detail/42?from=embed');

    expect($response['status'])->toBe(204, $response['body'])
        ->and(postedEvents())->toBe([
            ['event' => '__deeplink', 'payload' => ['uri' => '/detail/42?from=embed']],
        ]);
});

it('hands over the root route without a query', function () {
    $response = fromWebView('/');

    expect($response['status'])->toBe(204, $response['body'])
        ->and(postedEvents())->toBe([
            ['event' => '__deeplink', 'payload' => ['uri' => '/']],
        ]);
});

it('answers an error when native does not take the route, and still runs no screen', function () {
    app()->instance('native.refuses-events', true);
    config(['app.debug' => true]);

    $response = fromWebView('/detail/42');

    expect($response['status'])->toBe(500)
        ->and($response['body'])->toContain('can only run on the main lane')
        ->and(postedEvents())->toHaveCount(1);
});

it('leaves the web view lane alone for routes that are not native', function () {
    Route::get('/embedded/one', fn () => 'page one');

    $response = fromWebView('/embedded/one');

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toBe('page one')
        ->and(postedEvents())->toBe([]);
});

it('leaves native routes on the main lane as they were', function () {
    $this->get('/detail/42')
        ->assertSuccessful()
        ->assertSee(DetailScreen::class);

    expect(postedEvents())->toBe([]);
});
