<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\Asgard\ServerScreen;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Http\Middleware\OpensServerRoutes;
use Native\Mobile\Testing\FakeBridge;
use Tests\Fixtures\Edge\AppPromoScreen;

/**
 * A cold deep link or a hot-reload restore to a server route arrives as a
 * request, and Laravel's router has no route for it. The middleware opens
 * it as a server screen, behind every Laravel route.
 */
function openServerRoute(string $uri, string $method = 'GET', array $headers = ['Accept' => 'text/html,application/xhtml+xml']): string
{
    $request = Request::create($uri, $method);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return (string) app(OpensServerRoutes::class)->handle($request, fn () => response('passed through'))->getContent();
}

const SERVER_SCREEN_ENTRY = 'Native screen ['.ServerScreen::class.']';

beforeEach(function () {
    NativeRouter::clearRoutes();
    Route::native('/promos/new', AppPromoScreen::class);
    Route::get('/promos/web', fn () => 'web');
    NativeRouter::registerServerRoutes([
        ['uri' => '/promos/{id}', 'screen' => 'promos.show'],
        ['uri' => '/inbox', 'screen' => 'inbox'],
    ]);
});

afterEach(function () {
    NativeRouter::clearRoutes();
    FakeBridge::disable();
});

it('opens a server route no Laravel route claims', function (array $headers) {
    expect(openServerRoute('/promos/42?ref=mail', headers: $headers))->toStartWith(SERVER_SCREEN_ENTRY);
})->with([
    'a WebView page load' => [['Accept' => 'text/html,application/xhtml+xml']],
    'a direct native dispatch, with no Accept' => [[]],
]);

it('leaves Laravel routes to Laravel, native or web', function (string $uri) {
    expect(openServerRoute($uri))->toBe('passed through');
})->with(['/promos/new', '/promos/web']);

it('does not count a fallback route as a match', function () {
    Route::fallback(fn () => 'not found');

    expect(openServerRoute('/inbox'))->toStartWith(SERVER_SCREEN_ENTRY);
});

it('passes through paths no server route matches', function () {
    expect(openServerRoute('/nowhere'))->toBe('passed through');
});

it('leaves assets, XHRs and other methods alone', function (string $method, array $headers) {
    expect(openServerRoute('/promos/42', $method, $headers))->toBe('passed through');
})->with([
    'an asset' => ['GET', ['Accept' => '*/*']],
    'a stylesheet' => ['GET', ['Accept' => 'text/css,*/*;q=0.1']],
    'an XHR' => ['GET', ['Accept' => 'text/html', 'X-Requested-With' => 'XMLHttpRequest']],
    'a POST' => ['POST', ['Accept' => 'text/html']],
]);

it('names the server screen a URI opens, and nothing for app routes', function () {
    expect(NativeRouter::serverScreenFor('/promos/42'))->toBe('promos.show')
        ->and(NativeRouter::serverScreenFor('/promos/new'))->toBeNull()
        ->and(NativeRouter::serverScreenFor('/nowhere'))->toBeNull();
});

it('launches the ServerScreen at a server route with its address', function () {
    FakeBridge::enable();

    $router = new class extends NativeRouter
    {
        public ?object $launched = null;

        protected function loop(): ?string
        {
            $this->launched = $this->stack[0]['component'];

            return null;
        }
    };

    $router->start(ServerScreen::class, ['id' => '42'], '/promos/42?ref=mail');

    expect($router->launched)->toBeInstanceOf(ServerScreen::class)
        ->and($router->launched->asgardAddress())->toBe('promos.show');
});
