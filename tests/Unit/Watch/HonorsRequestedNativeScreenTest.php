<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Http\Middleware\HonorsRequestedNativeScreen;
use Tests\Fixtures\Edge\CounterScreen;

/**
 * The recovery path for a screen change: when the app has fallen through to the
 * WebView there is no runloop to read the intent, so an ordinary page load has
 * to claim it instead.
 */
function screenIntentPath(): string
{
    return base_path(NativeRouter::SCREEN_INTENT_PATH);
}

function pendingIntent(string $uri, ?int $ts = null): void
{
    file_put_contents(screenIntentPath(), json_encode([
        'uri' => $uri,
        'ts' => $ts ?? time(),
    ]));
}

/**
 * @param  array<string, string>  $headers
 */
function runMiddleware(string $uri = '/whatever', string $method = 'GET', array $headers = ['Accept' => 'text/html,application/xhtml+xml']): mixed
{
    $request = Request::create($uri, $method);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return (new HonorsRequestedNativeScreen)->handle(
        $request,
        fn () => response('passed through'),
    );
}

beforeEach(function () {
    NativeRouter::clearRoutes();
    @unlink(screenIntentPath());
    Route::native('/counter', CounterScreen::class);
});

afterEach(function () {
    NativeRouter::clearRoutes();
    @unlink(screenIntentPath());
});

it('passes the request through when no screen was requested', function () {
    expect(runMiddleware()->getContent())->toBe('passed through');
});

it('redirects a page load to the requested screen', function () {
    pendingIntent('/counter');

    $response = runMiddleware('/a-404-page');

    // Same shape of redirect `Route::native` already uses to land the app on a
    // native screen, so it travels the path the platforms already handle.
    expect($response->isRedirection())->toBeTrue();
    expect(parse_url($response->headers->get('Location'), PHP_URL_PATH))->toBe('/counter');
    expect(screenIntentPath())->not->toBeFile();
});

it('leaves the request alone when it is already the requested screen', function () {
    pendingIntent('/counter');

    // The native path's own re-entry lands here; redirecting would loop.
    expect(runMiddleware('/counter')->getContent())->toBe('passed through');
});

// An asset request must not burn the one shot we get and redirect a stylesheet
// to a screen, leaving the app exactly where it was.
it('does not let asset requests claim the intent', function () {
    pendingIntent('/counter');

    $response = runMiddleware('/build/app.css', headers: ['Accept' => 'text/css,*/*;q=0.1']);

    expect($response->getContent())->toBe('passed through');
    expect(screenIntentPath())->toBeFile();
});

it('does not let XHR claim the intent', function () {
    pendingIntent('/counter');

    $response = runMiddleware(headers: [
        'Accept' => 'text/html',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);

    expect($response->getContent())->toBe('passed through');
    expect(screenIntentPath())->toBeFile();
});

it('does not let a form post claim the intent', function () {
    pendingIntent('/counter');

    $response = runMiddleware('/submit', 'POST');

    expect($response->getContent())->toBe('passed through');
    expect(screenIntentPath())->toBeFile();
});

it('ignores a stale intent and still serves the request', function () {
    pendingIntent('/counter', time() - 3600);

    expect(runMiddleware()->getContent())->toBe('passed through');
});

it('ignores an intent for a screen that is not a native route', function () {
    pendingIntent('/not-a-native-route');

    expect(runMiddleware()->getContent())->toBe('passed through');
});
