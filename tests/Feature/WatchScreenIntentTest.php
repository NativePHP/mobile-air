<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;

/**
 * `native:watch`'s `L` key pushes a screen-change intent into the app's
 * base_path() and triggers an ordinary hot reload; PHP consumes the intent on
 * its way through the reload handler. These cover the consuming end.
 */
function intentPath(): string
{
    return base_path(NativeRouter::SCREEN_INTENT_PATH);
}

function writeScreenIntent(array $payload): void
{
    file_put_contents(intentPath(), json_encode($payload));
}

beforeEach(function () {
    NativeRouter::clearRoutes();
    @unlink(intentPath());
});

afterEach(function () {
    NativeRouter::clearRoutes();
    @unlink(intentPath());
});

it('returns nothing when no screen change has been requested', function () {
    expect(NativeRouter::takeScreenIntent())->toBeNull();
});

it('takes a requested screen and consumes the intent', function () {
    Route::native('/settings', CounterScreen::class);

    writeScreenIntent(['uri' => '/settings', 'ts' => time()]);

    expect(NativeRouter::takeScreenIntent())->toBe('/settings');
    expect(intentPath())->not->toBeFile();
});

it('normalises a requested screen to a leading slash', function () {
    Route::native('/settings', CounterScreen::class);

    writeScreenIntent(['uri' => 'settings', 'ts' => time()]);

    expect(NativeRouter::takeScreenIntent())->toBe('/settings');
});

it('takes a parameterised screen that matches a registered pattern', function () {
    Route::native('/detail/{id}', DetailScreen::class);

    writeScreenIntent(['uri' => '/detail/42', 'ts' => time()]);

    expect(NativeRouter::takeScreenIntent())->toBe('/detail/42');
});

// A stale intent must not hijack a hot reload that happens minutes later.
it('ignores an intent pushed while the app was closed', function () {
    Route::native('/settings', CounterScreen::class);

    writeScreenIntent(['uri' => '/settings', 'ts' => time() - 3600]);

    expect(NativeRouter::takeScreenIntent())->toBeNull();
});

it('ignores a screen that is not a native route', function () {
    Route::native('/settings', CounterScreen::class);

    writeScreenIntent(['uri' => '/some-web-page', 'ts' => time()]);

    expect(NativeRouter::takeScreenIntent())->toBeNull();
});

it('consumes an intent it refuses to act on, so it is not reconsidered', function () {
    writeScreenIntent(['uri' => '/gone-away', 'ts' => time()]);

    expect(NativeRouter::takeScreenIntent())->toBeNull();
    expect(intentPath())->not->toBeFile();
});

it('survives a corrupt intent file', function () {
    file_put_contents(intentPath(), 'not json at all');

    expect(NativeRouter::takeScreenIntent())->toBeNull();
    expect(intentPath())->not->toBeFile();
});

it('lands a hot reload on the requested screen, as the new root', function () {
    Route::native('/settings', CounterScreen::class);

    writeScreenIntent(['uri' => '/settings', 'ts' => time()]);

    expect(hotRestartPayload(new CounterScreen))->toBe([
        'uri' => '/settings',
        'stack' => [],
    ]);
});

it('lands a hot reload where the user already was when no screen was requested', function () {
    expect(hotRestartPayload(new CounterScreen))->toBe([
        'uri' => '/',
        'stack' => [],
    ]);
});

function hotRestartPayload(NativeComponent $component): array
{
    $method = new ReflectionMethod($component, 'hotRestartPayload');

    return $method->invoke($component);
}
