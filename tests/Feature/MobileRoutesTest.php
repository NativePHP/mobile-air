<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Filesystem\Filesystem;
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

    /**
     * A throwaway app root for each test that writes route files. Included
     * files last for the whole PHP process, so sharing one path would let
     * one test's include look like the next test's app loading the file.
     */
    private ?string $basePath = null;

    /** How the stand-in app route provider loads routes. */
    private ?Closure $appRoutes = null;

    /** nativephp-internal as config:cache wrote it, in place before providers register. */
    private ?array $cachedInternalConfig = null;

    /** NATIVEPHP_RUNNING as the process had it, in each place env() looks. */
    private array $nativephpRunning;

    protected function setUp(): void
    {
        $this->argv = $_SERVER['argv'];
        $this->jumpBridgePort = getenv('JUMP_BRIDGE_PORT');
        $this->nativephpRunning = [
            'getenv' => getenv('NATIVEPHP_RUNNING'),
            'server' => $_SERVER['NATIVEPHP_RUNNING'] ?? null,
            'env' => $_ENV['NATIVEPHP_RUNNING'] ?? null,
        ];

        NativeRouter::clearRoutes();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->basePath !== null) {
            (new Filesystem)->deleteDirectory($this->basePath);
        }

        unset($GLOBALS['mobile_routes_requires']);

        if ($this->routeCache !== null) {
            @unlink($this->routeCache);
            putenv('APP_ROUTES_CACHE');
        }

        NativeRouter::clearRoutes();

        $_SERVER['argv'] = $this->argv;
        putenv($this->jumpBridgePort === false ? 'JUMP_BRIDGE_PORT' : "JUMP_BRIDGE_PORT={$this->jumpBridgePort}");

        ['getenv' => $getenv, 'server' => $server, 'env' => $env] = $this->nativephpRunning;
        putenv($getenv === false ? 'NATIVEPHP_RUNNING' : "NATIVEPHP_RUNNING={$getenv}");
        unset($_SERVER['NATIVEPHP_RUNNING'], $_ENV['NATIVEPHP_RUNNING']);
        if ($server !== null) {
            $_SERVER['NATIVEPHP_RUNNING'] = $server;
        }
        if ($env !== null) {
            $_ENV['NATIVEPHP_RUNNING'] = $env;
        }

        parent::tearDown();
    }

    /**
     * Config loads before providers register, so a value set here wins over
     * the package's own config file, the same as a cached config does.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        if ($this->cachedInternalConfig !== null) {
            $app['config']->set('nativephp-internal', $this->cachedInternalConfig);
        }
    }

    /**
     * Stands in for the app's own route provider: App\Providers\RouteServiceProvider
     * on Laravel 10, withRouting() on 11+. Laravel registers that after package
     * providers, so it boots after NativeServiceProvider. So does this one.
     */
    protected function defineEnvironment($app)
    {
        if ($this->basePath !== null) {
            $app->setBasePath($this->basePath);
        }

        // The web middleware group encrypts cookies.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $routes = $this->appRoutes ?? function () {
            if (file_exists($web = base_path('routes/web.php'))) {
                Route::middleware('web')->group($web);
            }
        };

        $app->register(new class($app, $routes) extends RouteServiceProvider
        {
            public function __construct($app, Closure $routes)
            {
                parent::__construct($app);

                $this->routes($routes);
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

    public function test_it_loads_mobile_routes_again_on_every_boot_in_one_process(): void
    {
        $this->bootWithRoutes(mobile: <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;
            use Tests\Fixtures\Edge\CounterScreen;

            Route::native('/', CounterScreen::class);
            PHP);

        // From here on the file is already included, as it is for every test
        // after the first in an app's own test suite.
        $this->assertContains(realpath(base_path('routes/mobile.php')), get_included_files());

        foreach ([1, 2, 3] as $boot) {
            if ($boot > 1) {
                NativeRouter::clearRoutes();
                $this->refreshApplication();
            }

            $this->assertSame(CounterScreen::class, NativeRouter::registeredRoutes()['/']['class'] ?? null, "boot {$boot}");
            $this->assertArrayHasKey('/', $this->app['router']->getRoutes()->getRoutesByMethod()['GET'], "boot {$boot}");
        }
    }

    public function test_it_leaves_an_app_that_loads_mobile_routes_itself_alone(): void
    {
        $this->appRoutes = function () {
            Route::middleware(['web', 'auth'])->prefix('app')->group(base_path('routes/mobile.php'));
        };

        $this->bootWithRoutes(mobile: <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;
            use Tests\Fixtures\Edge\CounterScreen;

            Route::native('/', CounterScreen::class);
            PHP);

        $routes = $this->app['router']->getRoutes()->getRoutesByMethod()['GET'];

        $this->assertSame(['web', 'auth'], $routes['app']->gatherMiddleware());
        $this->assertArrayNotHasKey('/', $routes);
    }

    public function test_it_does_not_require_mobile_routes_again_when_the_app_already_did(): void
    {
        // Declares a function, so a second require in this process would be
        // fatal. The name is unique per run so the test can repeat.
        $helper = 'mobile_routes_helper_'.bin2hex(random_bytes(6));
        $GLOBALS['mobile_routes_requires'] = 0;

        $this->bootWithRoutes(
            web: <<<'PHP'
                <?php

                require __DIR__.'/mobile.php';
                PHP,
            mobile: str_replace('HELPER', $helper, <<<'PHP'
                <?php

                use Illuminate\Support\Facades\Route;
                use Tests\Fixtures\Edge\CounterScreen;

                $GLOBALS['mobile_routes_requires']++;

                function HELPER() {}

                Route::native('/', CounterScreen::class);
                PHP),
        );

        $this->assertSame(1, $GLOBALS['mobile_routes_requires']);
        $this->assertTrue(function_exists($helper));
        $this->assertArrayHasKey('/', $this->app['router']->getRoutes()->getRoutesByMethod()['GET']);
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

    public function test_the_environment_turns_it_on_when_a_cached_config_says_off(): void
    {
        // Android ran config:cache before its runtime set NATIVEPHP_RUNNING,
        // so the cache says false while every later boot runs on device.
        $this->cachedInternalConfig = ['running' => false, 'platform' => 'android', 'tempdir' => null];
        putenv('NATIVEPHP_RUNNING=true');

        $this->refreshApplication();

        $this->assertTrue(config('nativephp-internal.running'));

        $this->app['env'] = 'production';
        (fn () => $this->isRunningInConsole = false)->call($this->app);
        putenv('JUMP_BRIDGE_PORT');

        $this->assertTrue($this->shouldLoadMobileRoutes());
    }

    public function test_a_cached_config_stays_off_without_the_environment_variable(): void
    {
        $this->cachedInternalConfig = ['running' => false, 'platform' => null, 'tempdir' => null];
        putenv('NATIVEPHP_RUNNING');
        unset($_SERVER['NATIVEPHP_RUNNING'], $_ENV['NATIVEPHP_RUNNING']);

        $this->refreshApplication();

        $this->assertFalse(config('nativephp-internal.running'));

        $this->app['env'] = 'production';
        (fn () => $this->isRunningInConsole = false)->call($this->app);
        putenv('JUMP_BRIDGE_PORT');

        $this->assertFalse($this->shouldLoadMobileRoutes());
    }

    /**
     * Write the given route files into a fresh app root, then boot an app
     * there so they are loaded the way a real app loads them.
     */
    private function bootWithRoutes(?string $web = null, ?string $mobile = null): void
    {
        $this->basePath = sys_get_temp_dir().'/nativephp-mobile-routes-'.uniqid();
        mkdir($this->basePath.'/routes', 0755, true);

        if ($web !== null) {
            file_put_contents($this->basePath.'/routes/web.php', $web);
        }

        if ($mobile !== null) {
            file_put_contents($this->basePath.'/routes/mobile.php', $mobile);
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
