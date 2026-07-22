<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginRegistry;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\warning;

class PluginUninstallCommand extends Command
{
    protected $signature = 'native:plugin:uninstall
                            {plugin? : The plugin package name (e.g., vendor/plugin-name)}
                            {--force : Skip confirmation prompts}
                            {--keep-files : Do not delete the plugin source directory}
                            {--core-v4 : Uninstall the plugins that moved into core in v4 (device, dialog, file, system)}';

    protected $description = 'Uninstall a NativePHP Mobile plugin completely';

    /**
     * Plugins that were folded into the core package in v4, mapped to the
     * service provider they registered. These are no longer needed once the
     * app is on a core release that ships this functionality built-in.
     *
     * @var array<string, string>
     */
    protected array $coreV4Plugins = [
        'nativephp/mobile-device' => 'Native\\Mobile\\Providers\\DeviceServiceProvider',
        'nativephp/mobile-dialog' => 'Native\\Mobile\\Providers\\DialogServiceProvider',
        'nativephp/mobile-file' => 'Native\\Mobile\\Providers\\FileServiceProvider',
        'nativephp/mobile-system' => 'Native\\Mobile\\Providers\\SystemServiceProvider',
    ];

    public function __construct(
        protected Filesystem $files,
        protected PluginRegistry $registry
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('core-v4')) {
            return $this->handleCoreV4();
        }

        $pluginName = $this->argument('plugin');

        if (! $pluginName) {
            return $this->handleInteractive();
        }

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
        $this->showPluginDetails($pluginName, $pluginInfo);
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

        $succeeded = $this->uninstallPlugins([$pluginName => $pluginInfo]);

        $this->newLine();

        if (! $succeeded) {
            error("Plugin '{$pluginName}' could not be fully uninstalled. Re-run with -v for Composer output.");

            return self::FAILURE;
        }

        info("Plugin '{$pluginName}' has been uninstalled.");

        return self::SUCCESS;
    }

    /**
     * No package given: let the user pick what to uninstall from the plugins
     * that are actually removable in this project.
     */
    protected function handleInteractive(): int
    {
        if (! $this->input->isInteractive()) {
            error('Provide a plugin package name, or pass --core-v4 to remove the plugins that moved into core.');

            return self::FAILURE;
        }

        $candidates = $this->uninstallCandidates();

        if ($candidates->isEmpty()) {
            info('No uninstallable NativePHP plugins found in this project.');

            $this->noteTransitivePlugins();

            return self::SUCCESS;
        }

        $selected = multiselect(
            label: 'Which plugins would you like to uninstall?',
            options: $candidates->all(),
            hint: 'Space to toggle, Enter to confirm',
        );

        if (empty($selected)) {
            info('No plugins selected.');

            return self::SUCCESS;
        }

        $pluginInfo = [];

        foreach ($selected as $packageName) {
            $info = $this->getPluginInfo($packageName);

            if (! $info) {
                warning("Plugin '{$packageName}' is not installed. Skipping.");

                continue;
            }

            $pluginInfo[$packageName] = $info;
        }

        if (empty($pluginInfo)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Uninstalling '.count($pluginInfo).' plugin(s)');
        $this->newLine();

        foreach ($pluginInfo as $packageName => $info) {
            $this->showPluginDetails($packageName, $info);
        }

        $this->newLine();

        if (! $this->option('force')) {
            $confirmed = confirm(
                label: 'Are you sure you want to uninstall the selected plugin(s)?',
                default: false,
                hint: 'This will remove the packages and optionally delete source files'
            );

            if (! $confirmed) {
                warning('Uninstall cancelled.');

                return self::SUCCESS;
            }
        }

        $this->newLine();

        $succeeded = $this->uninstallPlugins($pluginInfo);

        $this->newLine();

        if (! $succeeded) {
            error('Some packages could not be removed. Re-run with -v for Composer output.');

            return self::FAILURE;
        }

        info('Uninstalled: '.implode(', ', array_keys($pluginInfo)));

        return self::SUCCESS;
    }

    /**
     * Plugins that can actually be uninstalled: installed NativePHP plugins that
     * this project requires directly, plus any core-v4 leftovers still required.
     *
     * @return Collection<string, string> Package name => multiselect label
     */
    protected function uninstallCandidates(): Collection
    {
        $required = $this->directRequirements();
        $candidates = collect();

        foreach ($this->registry->allInstalled() as $plugin) {
            /** @var Plugin $plugin */
            if (! isset($required[$plugin->name])) {
                continue;
            }

            $candidates[$plugin->name] = $this->candidateLabel($plugin);
        }

        // Core-v4 packages are plain libraries, so they never surface via the
        // registry - offer them here too rather than hiding them behind the flag.
        foreach ($this->coreV4Plugins as $package => $provider) {
            if (! isset($required[$package]) || $candidates->has($package)) {
                continue;
            }

            $candidates[$package] = "{$package} [now built into core]";
        }

        return $candidates;
    }

    protected function candidateLabel(Plugin $plugin): string
    {
        $description = $plugin->description ? " - {$plugin->description}" : '';
        $status = $this->registry->isRegistered($plugin->name) ? 'registered' : 'not registered';

        $label = "{$plugin->name} (v{$plugin->version}){$description} [{$status}]";

        if (isset($this->coreV4Plugins[$plugin->name])) {
            $label .= ' [now built into core]';
        }

        return $label;
    }

    /**
     * Mention plugins that are installed but pulled in by another package, since
     * they can't be removed from this project directly.
     */
    protected function noteTransitivePlugins(): void
    {
        $required = $this->directRequirements();

        $transitive = $this->registry->allInstalled()
            ->reject(fn (Plugin $plugin) => isset($required[$plugin->name]));

        if ($transitive->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->warn('Installed as a dependency of another package (not directly removable):');

        foreach ($transitive as $plugin) {
            $this->line("  - {$plugin->name} (v{$plugin->version})");
        }
    }

    /**
     * Print the "here's what will be removed" block for a single plugin.
     */
    protected function showPluginDetails(string $pluginName, array $pluginInfo): void
    {
        $this->components->twoColumnDetail('Package', $pluginName);

        if ($pluginInfo['path_repository']) {
            $this->components->twoColumnDetail('Source directory', $pluginInfo['source_path']);
            $this->components->twoColumnDetail('Repository URL', $pluginInfo['repository_url']);
        }

        if ($pluginInfo['service_provider']) {
            $this->components->twoColumnDetail('Service provider', $pluginInfo['service_provider']);
        }
    }

    /**
     * Run the uninstall steps for one or more plugins. Packages are removed in a
     * single Composer call so a multi-select doesn't pay for a resolve per plugin.
     *
     * @param  array<string, array>  $pluginInfo  Package name => info from getPluginInfo()
     */
    protected function uninstallPlugins(array $pluginInfo): bool
    {
        // Step 1: Unregister from NativeServiceProvider
        foreach ($pluginInfo as $packageName => $info) {
            if ($info['service_provider']) {
                $this->components->task("Unregistering {$packageName}", function () use ($info) {
                    return $this->unregisterPlugin($info['service_provider']);
                });
            }
        }

        // Step 2: Run composer remove
        $packages = array_keys($pluginInfo);
        $removed = false;

        $this->components->task('Removing package(s) via Composer', function () use ($packages, &$removed) {
            return $removed = $this->removePackage(implode(' ', $packages));
        });

        // Step 3: Remove repositories from composer.json (if path repositories)
        foreach ($pluginInfo as $packageName => $info) {
            if ($info['path_repository'] && $info['repository_url']) {
                $this->components->task("Removing repository for {$packageName} from composer.json", function () use ($info) {
                    return $this->removeRepository($info['repository_url']);
                });
            }
        }

        // Step 4: Delete source directories (if path repositories and not --keep-files)
        if (! $this->option('keep-files')) {
            foreach ($pluginInfo as $packageName => $info) {
                if (! $info['path_repository'] || ! $info['source_path']) {
                    continue;
                }

                $sourcePath = $info['source_path'];

                if (! $this->files->isDirectory($sourcePath)) {
                    continue;
                }

                $deleteFiles = $this->option('force') || confirm(
                    label: "Delete plugin source directory for {$packageName}?",
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

        return $removed;
    }

    /**
     * Uninstall the plugins that were folded into core in v4.
     */
    protected function handleCoreV4(): int
    {
        $composerJsonPath = base_path('composer.json');

        if (! $this->files->exists($composerJsonPath)) {
            error('No composer.json found in this project.');

            return self::FAILURE;
        }

        $required = $this->directRequirements();

        // Which of the four are actually present as direct requirements?
        $present = array_filter(
            $this->coreV4Plugins,
            fn ($provider, $package) => isset($required[$package]),
            ARRAY_FILTER_USE_BOTH
        );

        if (empty($present)) {
            info('None of the core-v4 plugins (device, dialog, file, system) are installed. Nothing to do.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Uninstalling plugins that moved into core in v4');
        $this->newLine();

        foreach ($present as $package => $provider) {
            $this->components->twoColumnDetail($package, class_basename($provider));
        }

        $this->newLine();

        if (! $this->option('force')) {
            $confirmed = confirm(
                label: 'Remove these packages and unregister their service providers?',
                default: false,
                hint: 'This functionality is now built into the core package'
            );

            if (! $confirmed) {
                warning('Uninstall cancelled.');

                return self::SUCCESS;
            }
        }

        $this->newLine();

        // Step 1: Unregister each provider from NativeServiceProvider
        foreach ($present as $package => $provider) {
            $this->components->task("Unregistering {$package}", function () use ($provider) {
                return $this->unregisterPlugin($provider);
            });
        }

        // Step 2: Remove all present packages in a single composer call
        $packages = array_keys($present);
        $this->components->task('Removing packages via Composer', function () use ($packages) {
            return $this->removePackage(implode(' ', $packages));
        });

        $this->newLine();
        info('Removed: '.implode(', ', $packages));

        return self::SUCCESS;
    }

    /**
     * The project's direct Composer requirements (require + require-dev).
     *
     * @return array<string, string>
     */
    protected function directRequirements(): array
    {
        $composerJsonPath = base_path('composer.json');

        if (! $this->files->exists($composerJsonPath)) {
            return [];
        }

        $composerJson = json_decode($this->files->get($composerJsonPath), true) ?: [];

        return array_merge(
            $composerJson['require'] ?? [],
            $composerJson['require-dev'] ?? []
        );
    }

    /**
     * Get information about an installed plugin.
     */
    protected function getPluginInfo(string $pluginName): ?array
    {
        $composerJsonPath = base_path('composer.json');
        $installedJsonPath = base_path('vendor/composer/installed.json');

        if (! $this->files->exists($composerJsonPath)) {
            return null;
        }

        $composerJson = json_decode($this->files->get($composerJsonPath), true);

        // Check if package is in require or require-dev
        if (! isset($this->directRequirements()[$pluginName])) {
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

        // Drop the `use Vendor\Plugin\ServiceProvider;` import line entirely.
        $newContent = preg_replace(
            '/^use\s+\\\\?'.preg_quote($serviceProvider, '/').';[ \t]*\n/m',
            '',
            $content
        );

        // Remove the service provider entry from the plugins() array
        // Match patterns like: \Vendor\Plugin\ServiceProvider::class,
        $patterns = [
            // With leading backslash and ::class
            '/\s*\\\\'.preg_quote($serviceProvider, '/').'::class,?\s*\n?/',
            // Without leading backslash and ::class
            '/\s*'.preg_quote($serviceProvider, '/').'::class,?\s*\n?/',
            // Just the class name (short form)
            '/\s*'.preg_quote(class_basename($serviceProvider), '/').'::class,?\s*\n?/',
        ];

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
