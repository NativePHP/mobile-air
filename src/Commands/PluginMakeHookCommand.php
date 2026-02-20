<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Native\Mobile\Plugins\PluginRegistry;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;

class PluginMakeHookCommand extends Command
{
    protected $signature = 'native:plugin:make-hook
                            {plugin? : Path to the plugin directory}
                            {hook? : Hook type (pre_compile, post_compile, copy_assets, post_build)}
                            {--force : Overwrite existing file}';

    protected $description = 'Create a lifecycle hook command for a NativePHP plugin';

    protected Filesystem $files;

    protected PluginRegistry $registry;

    protected array $validHooks = [
        'pre_compile',
        'post_compile',
        'copy_assets',
        'post_build',
    ];

    protected array $hookDescriptions = [
        'pre_compile' => 'Runs before native code is compiled',
        'post_compile' => 'Runs after native code compilation',
        'copy_assets' => 'Copy assets to native project',
        'post_build' => 'Runs after native build completes',
    ];

    public function __construct(Filesystem $files, PluginRegistry $registry)
    {
        parent::__construct();
        $this->files = $files;
        $this->registry = $registry;
    }

    public function handle(): int
    {
        intro('Create a Plugin Hook Command');

        // Get plugin path - either from argument or interactive selection
        $pluginPath = $this->argument('plugin');

        if (! $pluginPath) {
            $pluginPath = $this->selectPlugin();
            if (! $pluginPath) {
                return self::FAILURE;
            }
        }

        // Resolve relative paths
        if (! str_starts_with($pluginPath, '/')) {
            $pluginPath = base_path($pluginPath);
        }

        // Validate plugin path
        if (! $this->files->isDirectory($pluginPath)) {
            $this->error("Plugin directory not found: {$pluginPath}");

            return self::FAILURE;
        }

        // Read plugin manifest
        $manifestPath = $pluginPath.'/nativephp.json';
        if (! $this->files->exists($manifestPath)) {
            $this->error("Plugin manifest not found: {$manifestPath}");

            return self::FAILURE;
        }

        $manifest = json_decode($this->files->get($manifestPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON in nativephp.json');

            return self::FAILURE;
        }

        // Get hook type - either from argument or interactive selection
        $hooks = $this->argument('hook');

        if (! $hooks) {
            $hooks = $this->selectHooks($manifest);
            if (empty($hooks)) {
                $this->warn('No hooks selected.');

                return self::SUCCESS;
            }
        } else {
            // Single hook from argument - convert to array
            $hooks = [$hooks];
        }

        // Validate hook types
        foreach ($hooks as $hook) {
            if (! in_array($hook, $this->validHooks)) {
                $this->error("Invalid hook type: {$hook}");
                $this->line('Valid hooks: '.implode(', ', $this->validHooks));

                return self::FAILURE;
            }
        }

        $namespace = $manifest['namespace'] ?? 'Plugin';

        // Detect PHP namespace from composer.json
        $composerPath = $pluginPath.'/composer.json';
        $phpNamespace = $this->detectPhpNamespace($composerPath) ?? 'Vendor\\Plugin';

        // Create Commands directory
        $commandsDir = $pluginPath.'/src/Commands';
        $this->files->ensureDirectoryExists($commandsDir);

        $createdCommands = [];

        foreach ($hooks as $hook) {
            // Generate command class name and signature
            $className = $this->getClassName($hook);
            $signature = $this->getSignature($namespace, $hook);

            // Check if file exists
            $filePath = $commandsDir.'/'.$className.'.php';
            if ($this->files->exists($filePath) && ! $this->option('force')) {
                $this->warn("File already exists: src/Commands/{$className}.php (use --force to overwrite)");

                continue;
            }

            // Generate the command
            $content = $this->generateCommand($phpNamespace, $className, $signature, $hook, $namespace);
            $this->files->put($filePath, $content);

            $this->info("Created hook command: src/Commands/{$className}.php");

            // Update manifest
            $this->updateManifest($manifestPath, $manifest, $hook, $signature);

            // Reload manifest for next iteration
            $manifest = json_decode($this->files->get($manifestPath), true);

            $createdCommands[] = [
                'class' => $className,
                'namespace' => $phpNamespace,
            ];
        }

        // Update the ServiceProvider with all created commands
        if (! empty($createdCommands)) {
            $this->updateServiceProvider($pluginPath, $namespace, $phpNamespace, $createdCommands);

            outro('Hook commands created and registered successfully!');
        }

        return self::SUCCESS;
    }

    protected function selectPlugin(): ?string
    {
        // Discover installed plugins
        $plugins = $this->registry->all();

        if ($plugins->isEmpty()) {
            $this->error('No plugins found. Create one first with: php artisan native:plugin:create');

            return null;
        }

        $options = [];
        foreach ($plugins as $plugin) {
            $path = $plugin->path;
            $name = $plugin->name;
            $namespace = $plugin->getNamespace();

            // Make path relative for display
            $relativePath = str_replace(base_path().'/', '', $path);

            $options[$path] = "{$name} ({$namespace}) - {$relativePath}";
        }

        return select(
            label: 'Which plugin do you want to add hooks to?',
            options: $options,
        );
    }

    protected function selectHooks(array $manifest): array
    {
        $existingHooks = $manifest['hooks'] ?? [];

        $options = [];
        foreach ($this->validHooks as $hook) {
            $description = $this->hookDescriptions[$hook];
            $exists = isset($existingHooks[$hook]);
            $label = $exists ? "{$hook} - {$description} (already exists)" : "{$hook} - {$description}";
            $options[$hook] = $label;
        }

        return multiselect(
            label: 'Which hooks do you want to create?',
            options: $options,
            hint: 'Space to select, Enter to confirm. Existing hooks can be overwritten with --force.',
        );
    }

    protected function detectPhpNamespace(string $composerPath): ?string
    {
        if (! $this->files->exists($composerPath)) {
            return null;
        }

        $composer = json_decode($this->files->get($composerPath), true);
        $autoload = $composer['autoload']['psr-4'] ?? [];

        foreach ($autoload as $namespace => $path) {
            if ($path === 'src/' || $path === 'src') {
                return rtrim($namespace, '\\');
            }
        }

        return null;
    }

    protected function getClassName(string $hook): string
    {
        return match ($hook) {
            'pre_compile' => 'PreCompileCommand',
            'post_compile' => 'PostCompileCommand',
            'copy_assets' => 'CopyAssetsCommand',
            'post_build' => 'PostBuildCommand',
        };
    }

    protected function getSignature(string $namespace, string $hook): string
    {
        $kebab = Str::kebab($namespace);
        $hookKebab = str_replace('_', '-', $hook);

        return "nativephp:{$kebab}:{$hookKebab}";
    }

    protected function generateCommand(string $phpNamespace, string $className, string $signature, string $hook, string $pluginNamespace): string
    {
        $description = $this->hookDescriptions[$hook];
        $exampleCode = $this->getExampleCode($hook);

        return <<<PHP
<?php

namespace {$phpNamespace}\\Commands;

use Native\\Mobile\\Plugins\\Commands\\NativePluginHookCommand;

/**
 * {$description}
 *
 * Available helpers:
 * - \$this->platform() - 'ios' or 'android'
 * - \$this->isAndroid(), \$this->isIos()
 * - \$this->buildPath() - Path to native project
 * - \$this->pluginPath() - Path to this plugin
 * - \$this->appId() - e.g., 'com.example.app'
 * - \$this->copyToAndroidAssets(\$src, \$dest)
 * - \$this->copyToIosBundle(\$src, \$dest)
 * - \$this->downloadIfMissing(\$url, \$dest)
 */
class {$className} extends NativePluginHookCommand
{
    protected \$signature = '{$signature}';

    protected \$description = '{$description} for {$pluginNamespace}';

    public function handle(): int
    {
{$exampleCode}

        return self::SUCCESS;
    }
}
PHP;
    }

    protected function getExampleCode(string $hook): string
    {
        return match ($hook) {
            'pre_compile' => <<<'CODE'
        // Example: Download a model if not present
        // $modelPath = $this->pluginPath() . '/resources/models/model.bin';
        // $this->downloadIfMissing('https://example.com/model.bin', $modelPath);

        $this->info("Pre-compile hook running for {$this->platform()}");
CODE,
            'post_compile' => <<<'CODE'
        // Example: Modify generated code or run validation

        $this->info("Post-compile hook running for {$this->platform()}");
CODE,
            'copy_assets' => <<<'CODE'
        if ($this->isAndroid()) {
            // Copy assets to Android project
            // $this->copyToAndroidAssets('models/model.tflite', 'models/model.tflite');
            $this->info('Android assets copied');
        }

        if ($this->isIos()) {
            // Copy assets to iOS bundle
            // $this->copyToIosBundle('models/model.mlmodel', 'models/model.mlmodel');
            $this->info('iOS assets copied');
        }
CODE,
            'post_build' => <<<'CODE'
        // Example: Run after build completes
        // Could upload to TestFlight, notify team, etc.

        $this->info("Build completed for {$this->platform()}");
CODE,
        };
    }

    protected function updateManifest(string $manifestPath, array $manifest, string $hook, string $signature): void
    {
        if (! isset($manifest['hooks'])) {
            $manifest['hooks'] = [];
        }

        $isNew = ! isset($manifest['hooks'][$hook]);
        $manifest['hooks'][$hook] = $signature;

        $this->files->put(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );

        if ($isNew) {
            $this->info("Added hook to nativephp.json: {$hook}");
        } else {
            $this->info("Updated hook in nativephp.json: {$hook}");
        }
    }

    protected function updateServiceProvider(string $pluginPath, string $namespace, string $phpNamespace, array $createdCommands): void
    {
        // Find the service provider file
        $serviceProviderPath = $pluginPath.'/src/'.$namespace.'ServiceProvider.php';

        if (! $this->files->exists($serviceProviderPath)) {
            $this->warn("ServiceProvider not found at: {$serviceProviderPath}");
            $this->warn("You'll need to manually register the commands.");

            return;
        }

        $content = $this->files->get($serviceProviderPath);

        // Build use statements and command class references for the new commands
        $newUseStatements = [];
        $newCommandClasses = [];

        foreach ($createdCommands as $cmd) {
            $fullClass = $cmd['namespace'].'\\Commands\\'.$cmd['class'];
            $useStatement = "use {$fullClass};";
            $commandClass = $cmd['class'].'::class';

            // Check if already imported/registered
            if (! str_contains($content, $useStatement)) {
                $newUseStatements[] = $useStatement;
            }

            if (! str_contains($content, $commandClass)) {
                $newCommandClasses[] = $commandClass;
            }
        }

        if (empty($newUseStatements) && empty($newCommandClasses)) {
            $this->info('ServiceProvider already has all commands registered.');

            return;
        }

        // Add use statements after the namespace declaration
        if (! empty($newUseStatements)) {
            // Find the last use statement or namespace declaration
            if (preg_match('/^(namespace [^;]+;)\s*((?:use [^;]+;\s*)*)/m', $content, $matches)) {
                $namespaceDecl = $matches[1];
                $existingUses = trim($matches[2]);

                $allUses = $existingUses ? $existingUses."\n".implode("\n", $newUseStatements) : implode("\n", $newUseStatements);

                $content = preg_replace(
                    '/^(namespace [^;]+;)\s*((?:use [^;]+;\s*)*)/m',
                    "$namespaceDecl\n\n{$allUses}\n",
                    $content
                );
            }
        }

        // Add commands to $this->commands() array
        if (! empty($newCommandClasses)) {
            // Look for existing $this->commands([ pattern
            if (preg_match('/\$this->commands\(\[\s*([^\]]*)\]\)/s', $content, $matches)) {
                $existingCommands = trim($matches[1]);
                $indent = '                ';

                // Build new commands list
                $commandsList = $existingCommands;
                foreach ($newCommandClasses as $cmdClass) {
                    if (! empty($commandsList) && ! str_ends_with(trim($commandsList), ',')) {
                        $commandsList .= ',';
                    }
                    $commandsList .= "\n{$indent}{$cmdClass},";
                }

                $content = preg_replace(
                    '/\$this->commands\(\[\s*([^\]]*)\]\)/s',
                    "\$this->commands([\n{$indent}".ltrim($commandsList)."\n            ])",
                    $content
                );
            } else {
                // No existing commands() call - we need to add one
                // Look for boot() method
                if (preg_match('/(public function boot\(\)[^{]*\{)/s', $content, $matches)) {
                    $bootDecl = $matches[1];
                    $indent = '        ';

                    $commandsCode = "\n{$indent}if (\$this->app->runningInConsole()) {\n";
                    $commandsCode .= "{$indent}    \$this->commands([\n";
                    foreach ($newCommandClasses as $cmdClass) {
                        $commandsCode .= "{$indent}        {$cmdClass},\n";
                    }
                    $commandsCode .= "{$indent}    ]);\n";
                    $commandsCode .= "{$indent}}\n";

                    $content = str_replace($bootDecl, $bootDecl.$commandsCode, $content);
                }
            }
        }

        $this->files->put($serviceProviderPath, $content);
        $this->info('Updated ServiceProvider with command registrations.');
    }
}
