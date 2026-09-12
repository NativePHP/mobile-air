<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;

beforeEach(function () {
    NativeRouter::clearRoutes();
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

it('answers native routes with a stub response instead of entering the runloop', function () {
    // This asserts the UNBOUND default — a package may have bound a
    // NativeRouteFallback, which takes precedence over the stub.
    unset(app()[NativeRouteFallback::class]);

    Route::native('/native-home', CounterScreen::class);

    $this->get('/native-home')
        ->assertSuccessful()
        ->assertSee(CounterScreen::class)
        ->assertSee('Native::test()', false);
});

it('registers native routes with the router for the component harness', function () {
    Route::native('/native-detail/{id}', DetailScreen::class);

    Native::visit('/native-detail/42', ['from' => 'http-route'])
        ->assertSee('Detail 42 from http-route');
});

it('resolves route parameters from the normalized path in subdirectory deployments', function () {
    Route::native('/native-subdir/{id}', SubdirectoryParamScreen::class);
    Native::fakeBridge();

    $originalEnvironment = app()->environment();
    app()->instance('env', 'production');

    $request = Request::create(
        'http://localhost/subdirectory/native-subdir/42?tab=details',
        'GET',
        server: [
            'SCRIPT_NAME' => '/subdirectory/index.php',
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
            'PHP_SELF' => '/subdirectory/index.php',
        ],
    );

    $kernel = app(Kernel::class);

    try {
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
    } finally {
        app()->instance('env', $originalEnvironment);
    }

    expect(SubdirectoryParamScreen::$mountedId)->toBe('42');
});

class SubdirectoryParamScreen extends NativeComponent
{
    public static string $mountedId = '';

    public function mount(string $id = 'missing'): void
    {
        static::$mountedId = $id;
        $this->exitToWeb('/done');
    }

    public function render(): Element
    {
        return Column::make(Text::make('Subdirectory route'));
    }
}
