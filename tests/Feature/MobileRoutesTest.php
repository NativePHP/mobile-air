<?php

namespace Tests\Feature;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\NativeServiceProvider;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\TestCase;

/**
 * The package loads an app's routes/mobile.php by itself, but only where
 * something native will use it. The same app can be deployed as a website,
 * and there its native screens must not answer over HTTP.
 */
class MobileRoutesTest extends TestCase
{
    private array $argv;

    private string|false $jumpBridgePort;

    private ?string $routeCache = null;

    protected function setUp(): void
    {
        $this->argv = $_SERVER['argv'];
        $this->jumpBridgePort = getenv('JUMP_BRIDGE_PORT');

        NativeRouter::clearRoutes();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        @unlink(base_path('routes/mobile.php'));
        @unlink(base_path('routes/web.php'));

        if ($this->routeCache !== null) {
            @unlink($this->routeCache);
            putenv('APP_ROUTES_CACHE');
        }

        NativeRouter::clearRoutes();

        $_SERVER['argv'] = $this->argv;
        putenv($this->jumpBridgePort === false ? 'JUMP_BRIDGE_PORT' : "JUMP_BRIDGE_PORT={$this->jumpBridgePort}");

        parent::tearDown();
    }

    /**
     * Stands in for the app's own route provider: App\Providers\RouteServiceProvider
     * on Laravel 10, withRouting() on 11+. Laravel registers that after package
     * providers, so it boots after NativeServiceProvider. So does this one.
     */
    protected function defineEnvironment($app)
    {
        // The web middleware group encrypts cookies.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app->register(new class($app) extends RouteServiceProvider
        {
            public function boot(): void
            {
                $this->routes(function () {
                    if (file_exists($web = base_path('routes/web.php'))) {
                        Route::middleware('web')->group($web);
                    }
                });
            }
        });
    }

    public function test_it_loads_mobile_routes_when_running_tests(): void
    {
        $this->bootWithRoutes(mobile: <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;
            use Tests\Fixtures\Edge\CounterScreen;

            Route::native('/', CounterScreen::class);
            PHP);

        $this->assertSame(CounterScreen::class, NativeRouter::registeredRoutes()['/']['class'] ?? null);

        $route = $this->app['router']->getRoutes()->getRoutesByMethod()['GET']['/'];
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_it_does_nothing_without_a_mobile_routes_file(): void
    {
        $this->bootWithRoutes();

        $this->assertSame([], NativeRouter::registeredRoutes());
        $this->assertArrayNotHasKey('/', $this->app['router']->getRoutes()->getRoutesByMethod()['GET'] ?? []);
    }

    public function test_it_does_nothing_when_routes_are_cached(): void
    {
        $this->routeCache = sys_get_temp_dir().'/nativephp-routes-'.uniqid().'.php';
        file_put_contents($this->routeCache, '<?php');
        putenv("APP_ROUTES_CACHE={$this->routeCache}");

        $this->bootWithRoutes(mobile: <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;
            use Tests\Fixtures\Edge\CounterScreen;

            Route::native('/', CounterScreen::class);
            PHP);

        $this->assertTrue($this->app->routesAreCached());
        $this->assertSame([], NativeRouter::registeredRoutes());
    }

    public function test_mobile_routes_win_over_the_apps_web_routes_on_the_same_uri(): void
    {
        $this->bootWithRoutes(
            web: <<<'PHP'
                <?php

                use Illuminate\Support\Facades\Route;

                Route::get('/', fn () => 'website home');
                Route::get('/about', fn () => 'website about');
                PHP,
            mobile: <<<'PHP'
                <?php

                use Illuminate\Support\Facades\Route;
                use Tests\Fixtures\Edge\CounterScreen;

                Route::native('/', CounterScreen::class);
                PHP,
        );

        $this->get('/')
            ->assertOk()
            ->assertSee(CounterScreen::class)
            ->assertDontSee('website home');

        // Routes that only the website defines are left alone.
        $this->get('/about')->assertOk()->assertSee('website about');
    }

    public function test_it_stays_off_for_a_website_request(): void
    {
        $this->pretendToBeAWebsite();

        $this->assertFalse($this->shouldLoadMobileRoutes());
    }

    public function test_it_stays_off_for_console_commands_that_are_not_native(): void
    {
        $this->pretendToBeAWebsite();

        foreach (['route:cache', 'optimize', 'serve'] as $command) {
            $this->runningCommand($command);

            $this->assertFalse($this->shouldLoadMobileRoutes(), $command);
        }
    }

    public function test_it_turns_on_on_device(): void
    {
        $this->pretendToBeAWebsite();

        config(['nativephp-internal.running' => true]);

        $this->assertTrue($this->shouldLoadMobileRoutes());
    }

    public function test_it_turns_on_when_running_tests(): void
    {
        $this->pretendToBeAWebsite();

        $this->app['env'] = 'testing';

        $this->assertTrue($this->shouldLoadMobileRoutes());
    }

    public function test_it_turns_on_in_a_jump_session(): void
    {
        $this->pretendToBeAWebsite();

        putenv('JUMP_BRIDGE_PORT=3002');

        $this->assertTrue($this->shouldLoadMobileRoutes());
    }

    public function test_it_turns_on_for_native_commands(): void
    {
        $this->pretendToBeAWebsite();

        foreach (['native:run', 'native:package', 'native:watch', 'native:jump'] as $command) {
            $this->runningCommand($command);

            $this->assertTrue($this->shouldLoadMobileRoutes(), $command);
        }
    }

    /**
     * Write the given route files, then boot a fresh app so they are loaded
     * the way a real app loads them.
     */
    private function bootWithRoutes(?string $web = null, ?string $mobile = null): void
    {
        if ($web !== null) {
            file_put_contents(base_path('routes/web.php'), $web);
        }

        if ($mobile !== null) {
            file_put_contents(base_path('routes/mobile.php'), $mobile);
        }

        NativeRouter::clearRoutes();

        $this->refreshApplication();
    }

    /**
     * A web request on a server: not the test environment, not on device,
     * no Jump session and not running in the console.
     */
    private function pretendToBeAWebsite(): void
    {
        $this->app['env'] = 'production';
        config(['nativephp-internal.running' => false]);
        putenv('JUMP_BRIDGE_PORT');

        (fn () => $this->isRunningInConsole = false)->call($this->app);
    }

    private function runningCommand(string $command): void
    {
        (fn () => $this->isRunningInConsole = true)->call($this->app);

        $_SERVER['argv'] = ['artisan', $command];
    }

    private function shouldLoadMobileRoutes(): bool
    {
        $provider = $this->app->getProvider(NativeServiceProvider::class);

        return (fn () => $this->shouldLoadMobileRoutes())->call($provider);
    }
}
