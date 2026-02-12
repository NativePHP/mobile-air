<?php

namespace Native\Mobile;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Native\Mobile\Commands\BuildIosAppCommand;
use Native\Mobile\Commands\CheckBuildNumberCommand;
use Native\Mobile\Commands\CredentialsCommand;
use Native\Mobile\Commands\InstallCommand;
use Native\Mobile\Commands\LaunchEmulatorCommand;
use Native\Mobile\Commands\OpenProjectCommand;
use Native\Mobile\Commands\PackageCommand;
use Native\Mobile\Commands\PluginBoostCommand;
use Native\Mobile\Commands\PluginCreateCommand;
use Native\Mobile\Commands\PluginInstallAgentCommand;
use Native\Mobile\Commands\PluginListCommand;
use Native\Mobile\Commands\PluginMakeHookCommand;
use Native\Mobile\Commands\PluginRegisterCommand;
use Native\Mobile\Commands\PluginUninstallCommand;
use Native\Mobile\Commands\PluginValidateCommand;
use Native\Mobile\Commands\ReleaseCommand;
use Native\Mobile\Commands\RunCommand;
use Native\Mobile\Commands\TailCommand;
use Native\Mobile\Commands\VersionCommand;
use Native\Mobile\Commands\JumpCommand;
use Native\Mobile\Commands\WatchCommand;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Http\Middleware\RenderEdgeComponents;
use Native\Mobile\Support\Ios\PhpUrlGenerator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NativeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('nativephp-mobile')
            ->hasConfigFile('nativephp')
            ->hasViews()
            ->hasRoute('api')
            ->hasCommands([
                PackageCommand::class,
                BuildIosAppCommand::class,
                CheckBuildNumberCommand::class,
                CredentialsCommand::class,
                InstallCommand::class,
                RunCommand::class,
                OpenProjectCommand::class,
                LaunchEmulatorCommand::class,
                ReleaseCommand::class,
                JumpCommand::class,
                WatchCommand::class,
                TailCommand::class,
                VersionCommand::class,
                PluginBoostCommand::class,
                PluginCreateCommand::class,
                PluginInstallAgentCommand::class,
                PluginListCommand::class,
                PluginMakeHookCommand::class,
                PluginRegisterCommand::class,
                PluginUninstallCommand::class,
                PluginValidateCommand::class,
            ]);
    }

    public function packageRegistered()
    {
        $this->mergeConfigFrom($this->package->basePath('/../config/nativephp-internal.php'), 'nativephp-internal');

        $this->publishPluginsServiceProvider();
        $this->registerPluginServices();
        $this->prepForIos();
    }

    protected function publishPluginsServiceProvider(): void
    {
        $this->publishes([
            __DIR__.'/../resources/stubs/NativeServiceProvider.php.stub' => app_path('Providers/NativeServiceProvider.php'),
        ], 'nativephp-plugins-provider');
    }

    protected function registerPluginServices(): void
    {
        $this->app->singleton(\Native\Mobile\Plugins\PluginDiscovery::class, function ($app) {
            return new \Native\Mobile\Plugins\PluginDiscovery(
                $app->make(\Illuminate\Filesystem\Filesystem::class),
                base_path()
            );
        });

        $this->app->singleton(\Native\Mobile\Plugins\PluginRegistry::class, function ($app) {
            return new \Native\Mobile\Plugins\PluginRegistry(
                $app->make(\Native\Mobile\Plugins\PluginDiscovery::class)
            );
        });

        $this->app->singleton(\Native\Mobile\Plugins\Compilers\AndroidPluginCompiler::class, function ($app) {
            return new \Native\Mobile\Plugins\Compilers\AndroidPluginCompiler(
                $app->make(\Illuminate\Filesystem\Filesystem::class),
                $app->make(\Native\Mobile\Plugins\PluginRegistry::class),
                base_path('nativephp')
            );
        });

        $this->app->singleton(\Native\Mobile\Plugins\Compilers\IOSPluginCompiler::class, function ($app) {
            return new \Native\Mobile\Plugins\Compilers\IOSPluginCompiler(
                $app->make(\Illuminate\Filesystem\Filesystem::class),
                $app->make(\Native\Mobile\Plugins\PluginRegistry::class),
                base_path('nativephp')
            );
        });
    }

    public function boot()
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/resources/views', 'nativephp-mobile');
        $this->loadViewsFrom(__DIR__.'/../resources/jump/views', 'jump');
    }

    public function packageBooted()
    {
        $this->setupComposerPostUpdateScript();
        $this->registerNativeComponents();
        $this->registerMiddleware();
        $this->registerFilesystems();
        $this->registerBladeDirectives();
        $this->configureViteHotFile();

        Blade::component('nativephp-mobile::components.native-js-bridge', 'native-js-bridge');

        $blade = app('blade.compiler');
        $blade->precompiler(new NativeTagPrecompiler($blade));
    }

    protected function registerBladeDirectives(): void
    {
        Blade::if('mobile', function () {
            return Facades\System::isMobile();
        });

        Blade::if('web', function () {
            return ! Facades\System::isMobile();
        });

        Blade::if('ios', function () {
            return Facades\System::isIos();
        });

        Blade::if('android', function () {
            return Facades\System::isAndroid();
        });
    }

    protected function registerMiddleware(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(RenderEdgeComponents::class);
    }

    protected function registerFilesystems(): void
    {
        // Only register these filesystems when running in a NativePHP shell app
        if (! config('nativephp-internal.running')) {
            return;
        }

        $tempDir = config('nativephp-internal.tempdir');

        // Only register if we have a valid temp directory path
        if (empty($tempDir)) {
            return;
        }

        // Dynamically add the temp disk to the filesystems config
        config([
            'filesystems.disks.mobile_public' => [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => config('app.url').'/_assets/storage',
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ],
            'filesystems.disks.temp' => [
                'driver' => 'local',
                'root' => $tempDir,
                'throw' => false,
            ],
        ]);
    }

    private function prepForIos()
    {

        if (! config('nativephp-internal.running')) {
            return;
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }

        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            $app->instance('routes', $routes);

            return new PhpUrlGenerator(
                $routes,
                $app->rebinding(
                    'request',
                    function ($app, $request) {
                        $app['url']->setRequest($request);
                    }
                ),
                $app['config']['app.asset_url']
            );
        });
    }

    /**
     * Configure Vite to use platform-specific hot file paths.
     *
     * This allows iOS and Android to have separate hot files (ios-hot, android-hot)
     * so that both platforms can run simultaneously with their own Vite dev servers.
     */
    private function configureViteHotFile(): void
    {
        // Only configure when running inside NativePHP
        if (! config('nativephp-internal.running')) {
            return;
        }

        $hotFile = match (config('nativephp-internal.platform')) {
            'ios' => public_path('ios-hot'),
            'android' => public_path('android-hot'),
            default => public_path('hot'),
        };

        Vite::useHotFile($hotFile);
    }

    private function setupComposerPostUpdateScript()
    {
        // Temporarily disabled for testing
        return;

        // Only run in console/CLI context to avoid web requests
        if (! $this->app->runningInConsole()) {
            return;
        }

        $composerPath = base_path('composer.json');

        if (! file_exists($composerPath)) {
            return;
        }

        try {
            $composerContent = json_decode(file_get_contents($composerPath), true);

            if (! is_array($composerContent)) {
                return;
            }

            // Use 'both' on macOS (supports iOS + Android), 'android' on other platforms
            $platform = PHP_OS_FAMILY === 'Darwin' ? 'both' : 'android';
            $nativeInstallCommand = "@php artisan native:install {$platform} --force --without-icu";

            // Check if post-update-cmd already contains our command
            if (isset($composerContent['scripts']['post-update-cmd'])) {
                $postUpdateCmds = $composerContent['scripts']['post-update-cmd'];

                // Handle both string and array formats
                if (is_string($postUpdateCmds)) {
                    if ($postUpdateCmds === $nativeInstallCommand) {
                        return; // Already exists
                    }
                    // Check if it's an old version with different platform and replace it
                    if (preg_match('/@php artisan native:install (android|both|ios) --force --without-icu/', $postUpdateCmds)) {
                        $composerContent['scripts']['post-update-cmd'] = $nativeInstallCommand;

                        return;
                    }
                    // Convert to array and add our command
                    $composerContent['scripts']['post-update-cmd'] = [$postUpdateCmds, $nativeInstallCommand];
                } elseif (is_array($postUpdateCmds)) {
                    if (in_array($nativeInstallCommand, $postUpdateCmds)) {
                        return; // Already exists
                    }

                    // Check for existing native:install commands with different platforms and replace them
                    $foundExisting = false;
                    foreach ($postUpdateCmds as $index => $cmd) {
                        if (preg_match('/@php artisan native:install (android|both|ios) --force --without-icu/', $cmd)) {
                            $composerContent['scripts']['post-update-cmd'][$index] = $nativeInstallCommand;
                            $foundExisting = true;
                            break;
                        }
                    }

                    // If no existing command found, add to array
                    if (! $foundExisting) {
                        $composerContent['scripts']['post-update-cmd'][] = $nativeInstallCommand;
                    }
                }
            } else {
                // Create scripts section if it doesn't exist
                if (! isset($composerContent['scripts'])) {
                    $composerContent['scripts'] = [];
                }

                // Add our post-update-cmd
                $composerContent['scripts']['post-update-cmd'] = [$nativeInstallCommand];
            }

            // Write back to composer.json with pretty formatting
            $json = json_encode($composerContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($composerPath, $json.PHP_EOL);

        } catch (\Exception $e) {
            // Silently fail to avoid breaking the application
            // Could optionally log this if needed
        }
    }

    protected function registerNativeComponents(): void
    {
        $componentPath = __DIR__.'/Edge/Components';

        if (! is_dir($componentPath)) {
            return;
        }

        // Recursively find all PHP files in the Components directory
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($componentPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Get relative path from Components directory
            $relativePath = str_replace($componentPath.'/', '', $file->getPathname());

            // Remove .php extension
            $classPath = substr($relativePath, 0, -4);

            // Get just the class name for the component tag
            $className = basename($classPath);

            // Skip the base NativeComponent class
            if ($className === 'NativeComponent') {
                continue;
            }

            // Convert BottomNav -> bottom-nav
            $kebabName = ltrim(strtolower(preg_replace('/[A-Z]/', '-$0', $className)), '-');

            // Build the full namespaced class name (e.g., Native\Mobile\NativeUI\Components\Navigation\BottomNav)
            $componentClass = 'Native\\Mobile\\Edge\\Components\\'.str_replace('/', '\\', $classPath);

            if (class_exists($componentClass)) {
                Blade::component("native-{$kebabName}", $componentClass);
            }
        }
    }
}
