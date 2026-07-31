<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Plugins\PluginRegistry;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class PluginUninstallCommand extends Command
{
    protected $signature = 'native:plugin:uninstall
                            {plugin : The plugin package name (e.g., vendor/plugin-name)}
                            {--force : Skip confirmation prompts}
                            {--keep-files : Do not delete the plugin source directory}';

    protected $description = 'Uninstall a NativePHP Mobile plugin completely';

    public function __construct(
        protected Filesystem $files,
        protected PluginRegistry $registry
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pluginName = $this->argument('plugin');

        // Find the plugin in installed packages
        $pluginInfo = $this->getPluginInfo($pluginName);

        if (! $pluginInfo) {
            error("Plugin '{$pluginName}' is not installed.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Uninstalling plugin: {$pluginName}");

        // Show what will be removed
        $this->newLine();
        $this->components->twoColumnDetail('Package', $pluginName);

        if ($pluginInfo['path_repository']) {
            $this->components->twoColumnDetail('Source directory', $pluginInfo['source_path']);
            $this->components->twoColumnDetail('Repository URL', $pluginInfo['repository_url']);
        }

        if ($pluginInfo['service_provider']) {
            $this->components->twoColumnDetail('Service provider', $pluginInfo['service_provider']);
        }

        $this->newLine();

        // Confirm unless --force
        if (! $this->option('force')) {
            $confirmed = confirm(
                label: 'Are you sure you want to uninstall this plugin?',
                default: false,
                hint: 'This will remove the package and optionally delete source files'
            );

            if (! $confirmed) {
                warning('Uninstall cancelled.');

                return self::SUCCESS;
            }
        }

        $this->newLine();

        // Step 1: Unregister from NativeServiceProvider
        if ($pluginInfo['service_provider']) {
            $this->components->task('Unregistering plugin', function () use ($pluginInfo) {
                return $this->unregisterPlugin($pluginInfo['service_provider']);
            });
        }

        // Step 2: Run composer remove
        $this->components->task('Removing package via Composer', function () use ($pluginName) {
            return $this->removePackage($pluginName);
        });

        // Step 3: Remove repository from composer.json (if path repository)
        if ($pluginInfo['path_repository'] && $pluginInfo['repository_url']) {
            $this->components->task('Removing repository from composer.json', function () use ($pluginInfo) {
                return $this->removeRepository($pluginInfo['repository_url']);
            });
        }

        // Step 4: Delete source directory (if path repository and not --keep-files)
        if ($pluginInfo['path_repository'] && $pluginInfo['source_path'] && ! $this->option('keep-files')) {
            $sourcePath = $pluginInfo['source_path'];

            if ($this->files->isDirectory($sourcePath)) {
                $deleteFiles = $this->option('force') || confirm(
                    label: 'Delete plugin source directory?',
                    default: true,
                    hint: $sourcePath
                );

                if ($deleteFiles) {
                    $this->components->task('Deleting source directory', function () use ($sourcePath) {
                        return $this->files->deleteDirectory($sourcePath);
                    });
                }
            }
        }

        $this->newLine();
        info("Plugin '{$pluginName}' has been uninstalled.");

        return self::SUCCESS;
    }

    /**
     * Get information about an installed plugin.
     */
    protected function getPluginInfo(string $pluginName): ?array
    {
        $composerJsonPath = base_path('composer.json');
        $composerLockPath = base_path('composer.lock');
        $installedJsonPath = base_path('vendor/composer/installed.json');

        if (! $this->files->exists($composerJsonPath)) {
            return null;
        }

        $composerJson = json_decode($this->files->get($composerJsonPath), true);

        // Check if package is in require or require-dev
        $isRequired = isset($composerJson['require'][$pluginName])
            || isset($composerJson['require-dev'][$pluginName]);

        if (! $isRequired) {
            return null;
        }

        // Find repository info
        $repositoryUrl = null;
        $sourcePath = null;
        $isPathRepository = false;

        if (isset($composerJson['repositories'])) {
            foreach ($composerJson['repositories'] as $repo) {
                if (($repo['type'] ?? '') === 'path' && isset($repo['url'])) {
                    // Check if this repo matches our plugin
                    $repoPath = $this->resolveRepositoryPath($repo['url']);
                    $repoComposerPath = $repoPath.'/composer.json';

                    if ($this->files->exists($repoComposerPath)) {
                        $repoComposer = json_decode($this->files->get($repoComposerPath), true);
                        if (($repoComposer['name'] ?? '') === $pluginName) {
                            $isPathRepository = true;
                            $repositoryUrl = $repo['url'];
                            $sourcePath = $repoPath;
                            break;
                        }
                    }
                }
            }
        }

        // Get service provider from installed.json
        $serviceProvider = null;
        if ($this->files->exists($installedJsonPath)) {
            $installed = json_decode($this->files->get($installedJsonPath), true);
            $packages = $installed['packages'] ?? $installed;

            foreach ($packages as $package) {
                if (($package['name'] ?? '') === $pluginName) {
                    $serviceProvider = $package['extra']['laravel']['providers'][0] ?? null;
                    break;
                }
            }
        }

        return [
            'name' => $pluginName,
            'path_repository' => $isPathRepository,
            'repository_url' => $repositoryUrl,
            'source_path' => $sourcePath,
            'service_provider' => $serviceProvider,
        ];
    }

    /**
     * Resolve a repository URL to an absolute path.
     */
    protected function resolveRepositoryPath(string $url): string
    {
        if (str_starts_with($url, '/')) {
            return $url;
        }

        if (str_starts_with($url, './') || str_starts_with($url, '../')) {
            return realpath(base_path($url)) ?: base_path($url);
        }

        return base_path($url);
    }

    /**
     * Unregister the plugin from NativeServiceProvider.
     */
    protected function unregisterPlugin(string $serviceProvider): bool
    {
        $providerPath = app_path('Providers/NativeServiceProvider.php');

        if (! $this->files->exists($providerPath)) {
            return true;
        }

        $content = $this->files->get($providerPath);

        // Remove the service provider line from the plugins() array
        // Match patterns like: \Vendor\Plugin\ServiceProvider::class,
        $patterns = [
            // With leading backslash and ::class
            '/\s*\\\\'.preg_quote($serviceProvider, '/').'::class,?\s*\n?/',
            // Without leading backslash and ::class
            '/\s*'.preg_quote($serviceProvider, '/').'::class,?\s*\n?/',
            // Just the class name (short form)
            '/\s*'.preg_quote(class_basename($serviceProvider), '/').'::class,?\s*\n?/',
        ];

        $newContent = $content;
        foreach ($patterns as $pattern) {
            $newContent = preg_replace($pattern, "\n", $newContent);
        }

        if ($newContent !== $content) {
            // Clean up any double newlines in the array
            $newContent = preg_replace('/\[\s*\n\s*\n/', "[\n", $newContent);
            $newContent = preg_replace('/,\s*\n\s*\n\s*\]/', ",\n        ]", $newContent);

            $this->files->put($providerPath, $newContent);
        }

        return true;
    }

    /**
     * Remove the package via Composer.
     */
    protected function removePackage(string $pluginName): bool
    {
        $process = proc_open(
            "composer remove {$pluginName} --no-interaction 2>&1",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path()
        );

        if (! is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && $this->output->isVerbose()) {
            $this->line($output);
        }

        return $exitCode === 0;
    }

    /**
     * Remove a repository from composer.json.
     */
    protected function removeRepository(string $repositoryUrl): bool
    {
        $composerJsonPath = base_path('composer.json');

        if (! $this->files->exists($composerJsonPath)) {
            return false;
        }

        $composerJson = json_decode($this->files->get($composerJsonPath), true);

        if (! isset($composerJson['repositories'])) {
            return true;
        }

        $originalCount = count($composerJson['repositories']);

        $composerJson['repositories'] = array_values(array_filter(
            $composerJson['repositories'],
            fn ($repo) => ($repo['url'] ?? '') !== $repositoryUrl
        ));

        // Remove empty repositories array
        if (empty($composerJson['repositories'])) {
            unset($composerJson['repositories']);
        }

        if (count($composerJson['repositories'] ?? []) !== $originalCount) {
            $this->files->put(
                $composerJsonPath,
                json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        }

        return true;
    }
}
