<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Edge\CounterScreen;

beforeEach(function () {
    NativeRouter::clearRoutes();
    RecordingNativeRouteMiddleware::$requests = [];
    RedirectNativeRouteMiddleware::$response = null;
    BlockingNativeRouteMiddleware::$response = null;
    ThrowingNativeRouteMiddleware::$exception = null;
    AllowingNativeRouteMiddleware::$calls = 0;
    MountedNativeRouteScreen::$mounts = 0;
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

function runNativeRouteMiddleware(NativeRouter $router, string $uri): ?Response
{
    return (fn () => $this->runRouteMiddleware($uri))->call($router);
}

it('runs route middleware before an in-app native navigation continues', function () {
    app('router')->aliasMiddleware('native.record', RecordingNativeRouteMiddleware::class);

    Route::native('/protected/{id}', CounterScreen::class)
        ->middleware('native.record');

    $originalRequest = request();
    $user = new GenericUser(['id' => 42]);
    app('auth')->guard()->setUser($user);
    $originalFacadePath = RequestFacade::path();
    $originalRoute = Route::current();
    $originalRouterRequest = app('router')->getCurrentRequest();

    $response = runNativeRouteMiddleware(new NativeRouter, '/protected/42');

    expect($response)->toBeNull()
        ->and(request())->toBe($originalRequest)
        ->and(RequestFacade::path())->toBe($originalFacadePath)
        ->and(Route::current())->toBe($originalRoute)
        ->and(app('router')->getCurrentRequest())->toBe($originalRouterRequest)
        ->and(RecordingNativeRouteMiddleware::$requests)->toBe([
            [
                'path' => 'protected/42',
                'facade_path' => 'protected/42',
                'id' => '42',
                'bound_id' => '42',
                'facade_id' => '42',
                'router_path' => 'protected/42',
                'user' => $user,
            ],
        ]);
});

it('does not overwrite parameters on the registered route while checking another URI', function () {
    $route = Route::native('/patients/{id}', CounterScreen::class)
        ->middleware(AllowingNativeRouteMiddleware::class);
    $route->bind(Request::create('/patients/1', 'GET'));

    $response = runNativeRouteMiddleware(new NativeRouter, '/patients/2');

    expect($response)->toBeNull()
        ->and($route->parameter('id'))->toBe('1');
});

it('returns a redirect response when route middleware blocks native navigation', function () {
    Route::native('/protected', CounterScreen::class)
        ->middleware(RedirectNativeRouteMiddleware::class);

    $response = runNativeRouteMiddleware(new NativeRouter, '/protected');

    expect($response)->not->toBeNull()
        ->and($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toEndWith('/login');
});

it('does not preload a protected screen when its route middleware redirects', function () {
    Route::native('/protected', CounterScreen::class)
        ->middleware(RedirectNativeRouteMiddleware::class);

    $router = new NativeRouter;
    $router->preloadStack([['uri' => '/protected']]);

    expect($router->stackDepth())->toBe(0);
});

it('runs middleware and mounts an allowed preloaded screen', function () {
    Route::native('/allowed', MountedNativeRouteScreen::class)
        ->middleware(AllowingNativeRouteMiddleware::class);

    $router = new NativeRouter;
    $router->preloadStack([['uri' => '/allowed']]);

    expect(AllowingNativeRouteMiddleware::$calls)->toBe(1)
        ->and(MountedNativeRouteScreen::$mounts)->toBe(1)
        ->and($router->stackDepth())->toBe(1);
});

it('returns the middleware redirect instead of mounting a navigated screen', function () {
    Route::native('/protected', CounterScreen::class)
        ->middleware(RedirectNativeRouteMiddleware::class);

    Native::fakeBridge();

    $exitUri = (new NativeRouter)->start(NavigateToProtectedScreen::class, uri: '/');

    expect($exitUri)->toBe(RedirectNativeRouteMiddleware::$response)
        ->and($exitUri->getStatusCode())->toBe(307)
        ->and($exitUri->headers->get('X-Native-Middleware'))->toBe('blocked')
        ->and($exitUri->headers->get('Location'))->toEndWith('/login');
});

it('returns the middleware redirect instead of mounting a replaced screen', function () {
    Route::native('/protected', CounterScreen::class)
        ->middleware(RedirectNativeRouteMiddleware::class);

    Native::fakeBridge();

    $exitUri = (new NativeRouter)->start(ReplaceWithProtectedScreen::class, uri: '/');

    expect($exitUri)->toBe(RedirectNativeRouteMiddleware::$response)
        ->and($exitUri->getStatusCode())->toBe(307);
});

it('returns a non-redirect middleware response instead of mounting the screen', function () {
    Route::native('/protected', CounterScreen::class)
        ->middleware(BlockingNativeRouteMiddleware::class);

    Native::fakeBridge();

    $response = (new NativeRouter)->start(NavigateToProtectedScreen::class, uri: '/');

    expect($response)->toBe(BlockingNativeRouteMiddleware::$response)
        ->and($response->getStatusCode())->toBe(403)
        ->and($response->headers->get('X-Native-Middleware'))->toBe('forbidden');
});

it('reports and renders exceptions thrown by route middleware', function () {
    $exception = new RuntimeException('middleware failed');
    ThrowingNativeRouteMiddleware::$exception = $exception;

    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with($exception);
    $handler->shouldReceive('render')->once()->with(Mockery::type(Request::class), $exception)
        ->andReturn(response('rendered', 500));
    app()->instance(ExceptionHandler::class, $handler);

    Route::native('/protected', CounterScreen::class)
        ->middleware(ThrowingNativeRouteMiddleware::class);

    $response = runNativeRouteMiddleware(new NativeRouter, '/protected');

    expect($response?->getStatusCode())->toBe(500)
        ->and($response?->getContent())->toBe('rendered');
});

class RecordingNativeRouteMiddleware
{
    public static array $requests = [];

    public function handle(Request $request, Closure $next): mixed
    {
        self::$requests[] = [
            'path' => $request->path(),
            'facade_path' => RequestFacade::path(),
            'id' => $request->route('id'),
            'bound_id' => app(LaravelRoute::class)->parameter('id'),
            'facade_id' => Route::current()?->parameter('id'),
            'router_path' => app('router')->getCurrentRequest()?->path(),
            'user' => $request->user(),
        ];

        return $next($request);
    }
}

class RedirectNativeRouteMiddleware
{
    public static ?Response $response = null;

    public function handle(Request $request, Closure $next): mixed
    {
        return self::$response = redirect('/login', 307, [
            'X-Native-Middleware' => 'blocked',
        ]);
    }
}

class BlockingNativeRouteMiddleware
{
    public static ?Response $response = null;

    public function handle(Request $request, Closure $next): mixed
    {
        return self::$response = response('Forbidden', 403, [
            'X-Native-Middleware' => 'forbidden',
        ]);
    }
}

class ThrowingNativeRouteMiddleware
{
    public static ?RuntimeException $exception = null;

    public function handle(Request $request, Closure $next): mixed
    {
        throw self::$exception ?? new RuntimeException('middleware failed');
    }
}

class AllowingNativeRouteMiddleware
{
    public static int $calls = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        self::$calls++;

        return $next($request);
    }
}

class MountedNativeRouteScreen extends NativeComponent
{
    public static int $mounts = 0;

    public function mount(): void
    {
        self::$mounts++;
    }

    public function runLoop(): void
    {
        // The router only needs a finite lifecycle for this fixture.
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('Allowed'));
    }
}

class NavigateToProtectedScreen extends NativeComponent
{
    public function runLoop(): void
    {
        $this->navigate('/protected');
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('Root'));
    }
}

class ReplaceWithProtectedScreen extends NativeComponent
{
    public function runLoop(): void
    {
        $this->replace('/protected');
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('Root'));
    }
}
