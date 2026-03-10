<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Native\Mobile\Plugins\PluginDiscovery;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;

class PluginBoostCommand extends Command
{
    protected $signature = 'native:plugin:boost
                            {plugin? : The plugin name or path}
                            {--force : Overwrite existing guidelines}';

    protected $description = 'Create Boost AI guidelines for a NativePHP plugin';

    protected Filesystem $files;

    protected PluginDiscovery $discovery;

    public function __construct(Filesystem $files, PluginDiscovery $discovery)
    {
        parent::__construct();
        $this->files = $files;
        $this->discovery = $discovery;
    }

    public function handle(): int
    {
        intro('Create Boost AI Guidelines for NativePHP Plugin');

        $pluginPath = $this->resolvePluginPath();

        if (! $pluginPath) {
            return self::FAILURE;
        }

        $manifest = $this->loadManifest($pluginPath);

        if (! $manifest) {
            $this->error('Could not load nativephp.json manifest from plugin.');

            return self::FAILURE;
        }

        $guidelinesPath = $pluginPath.'/resources/boost/guidelines/core.blade.php';

        if ($this->files->exists($guidelinesPath) && ! $this->option('force')) {
            $this->error('Guidelines file already exists. Use --force to overwrite.');

            return self::FAILURE;
        }

        $this->createGuidelines($pluginPath, $manifest, $guidelinesPath);

        outro('Boost guidelines created successfully!');

        $this->newLine();
        $this->components->twoColumnDetail('Guidelines file', $guidelinesPath);
        $this->newLine();
        $this->line('When users install your plugin and run <comment>php artisan boost:install</comment>,');
        $this->line('these guidelines will be automatically loaded.');

        return self::SUCCESS;
    }

    protected function resolvePluginPath(): ?string
    {
        $plugin = $this->argument('plugin');

        // If path provided directly
        if ($plugin && $this->files->isDirectory($plugin)) {
            if ($this->files->exists($plugin.'/nativephp.json')) {
                return realpath($plugin);
            }
            $this->error("Directory exists but no nativephp.json found: {$plugin}");

            return null;
        }

        // Check packages directory
        if ($plugin) {
            $packagesPath = base_path("packages/{$plugin}");
            if ($this->files->isDirectory($packagesPath) && $this->files->exists($packagesPath.'/nativephp.json')) {
                return $packagesPath;
            }
        }

        // Discover installed plugins
        $plugins = $this->discovery->discoverAll();

        if (empty($plugins)) {
            // Check packages directory for any plugins
            $packagesDir = base_path('packages');
            if ($this->files->isDirectory($packagesDir)) {
                $packages = [];
                foreach ($this->files->directories($packagesDir) as $vendorDir) {
                    foreach ($this->files->directories($vendorDir) as $packageDir) {
                        if ($this->files->exists($packageDir.'/nativephp.json')) {
                            $manifest = json_decode($this->files->get($packageDir.'/nativephp.json'), true);
                            $packages[$packageDir] = $manifest['name'] ?? basename($packageDir);
                        }
                    }
                }

                if (! empty($packages)) {
                    $selected = select(
                        label: 'Select a plugin to add Boost guidelines',
                        options: $packages,
                    );

                    return $selected;
                }
            }

            $this->error('No plugins found. Create a plugin first with: php artisan native:plugin:create');

            return null;
        }

        // Let user select from discovered plugins
        $options = [];
        foreach ($plugins as $plugin) {
            $options[$plugin->getPath()] = $plugin->getName();
        }

        $selected = select(
            label: 'Select a plugin to add Boost guidelines',
            options: $options,
        );

        return $selected;
    }

    protected function loadManifest(string $pluginPath): ?array
    {
        $manifestPath = $pluginPath.'/nativephp.json';

        if (! $this->files->exists($manifestPath)) {
            return null;
        }

        return json_decode($this->files->get($manifestPath), true);
    }

    protected function createGuidelines(string $pluginPath, array $manifest, string $guidelinesPath): void
    {
        $this->files->ensureDirectoryExists(dirname($guidelinesPath));

        $name = $manifest['name'] ?? 'Plugin';
        $namespace = $manifest['namespace'] ?? 'Plugin';
        $description = $manifest['description'] ?? 'A NativePHP Mobile plugin';

        // Extract bridge functions
        $bridgeFunctions = $manifest['bridge_functions'] ?? [];

        // Extract events
        $events = $manifest['events'] ?? [];

        // Try to find the facade and implementation
        $facadeClass = $this->findFacadeClass($pluginPath, $namespace);
        $phpNamespace = $this->extractPhpNamespace($pluginPath);

        $content = $this->generateGuidelinesContent(
            name: $name,
            namespace: $namespace,
            description: $description,
            bridgeFunctions: $bridgeFunctions,
            events: $events,
            facadeClass: $facadeClass,
            phpNamespace: $phpNamespace
        );

        $this->files->put($guidelinesPath, $content);

        $this->info("Created {$guidelinesPath}");
    }

    protected function findFacadeClass(string $pluginPath, string $namespace): ?string
    {
        $facadePath = $pluginPath."/src/Facades/{$namespace}.php";

        if ($this->files->exists($facadePath)) {
            return $namespace;
        }

        // Check for any facade files
        $facadesDir = $pluginPath.'/src/Facades';
        if ($this->files->isDirectory($facadesDir)) {
            $files = $this->files->files($facadesDir);
            if (! empty($files)) {
                return pathinfo($files[0], PATHINFO_FILENAME);
            }
        }

        return $namespace;
    }

    protected function extractPhpNamespace(string $pluginPath): ?string
    {
        $composerPath = $pluginPath.'/composer.json';

        if (! $this->files->exists($composerPath)) {
            return null;
        }

        $composer = json_decode($this->files->get($composerPath), true);
        $autoload = $composer['autoload']['psr-4'] ?? [];

        foreach ($autoload as $ns => $path) {
            if ($path === 'src/' || $path === 'src') {
                return rtrim($ns, '\\');
            }
        }

        return null;
    }

    protected function generateGuidelinesContent(
        string $name,
        string $namespace,
        string $description,
        array $bridgeFunctions,
        array $events,
        ?string $facadeClass,
        ?string $phpNamespace
    ): string {
        $facadeFqn = $phpNamespace ? "{$phpNamespace}\\Facades\\{$facadeClass}" : $facadeClass;

        // Build methods documentation from bridge functions
        $methodsDocs = '';
        foreach ($bridgeFunctions as $fn) {
            $fnName = $fn['name'] ?? '';
            $fnDesc = $fn['description'] ?? '';

            // Extract method name (e.g., "Plugin.Execute" -> "execute")
            $parts = explode('.', $fnName);
            $methodName = lcfirst(end($parts));

            $methodsDocs .= "- `{$facadeClass}::{$methodName}()`: {$fnDesc}\n";
        }

        // Build events documentation
        $eventsDocs = '';
        foreach ($events as $event) {
            $eventClass = class_basename($event);
            $eventsDocs .= "- `{$eventClass}`: Listen with `#[OnNative({$eventClass}::class)]`\n";
        }

        $content = <<<BLADE
## {$name}

{$description}

### Installation

```bash
composer require {$name}
```

### PHP Usage (Livewire/Blade)

Use the `{$facadeClass}` facade:

@verbatim
<code-snippet name="Using {$namespace} Facade" lang="php">
use {$facadeFqn};

BLADE;

        // Add example usage for each bridge function
        foreach ($bridgeFunctions as $fn) {
            $fnName = $fn['name'] ?? '';
            $parts = explode('.', $fnName);
            $methodName = lcfirst(end($parts));

            $content .= "// {$fn['description']}\n";
            $content .= "\$result = {$facadeClass}::{$methodName}();\n";
        }

        $content .= <<<BLADE
</code-snippet>
@endverbatim

### Available Methods

{$methodsDocs}
BLADE;

        if (! empty($events)) {
            $content .= <<<BLADE

### Events

{$eventsDocs}

@verbatim
<code-snippet name="Listening for {$namespace} Events" lang="php">
use Native\Mobile\Attributes\OnNative;

BLADE;

            foreach ($events as $event) {
                $eventClass = class_basename($event);
                $handlerName = 'handle'.$eventClass;
                $content .= "#[OnNative({$eventClass}::class)]\n";
                $content .= "public function {$handlerName}(\$data)\n";
                $content .= "{\n";
                $content .= "    // Handle the event\n";
                $content .= "}\n";
            }

            $content .= <<<'BLADE'
</code-snippet>
@endverbatim
BLADE;
        }

        $content .= <<<BLADE


### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using {$namespace} in JavaScript" lang="javascript">
import { {$this->toCamelCase($namespace)} } from '@{$name}';

BLADE;

        foreach ($bridgeFunctions as $fn) {
            $fnName = $fn['name'] ?? '';
            $parts = explode('.', $fnName);
            $methodName = lcfirst(end($parts));

            $content .= "// {$fn['description']}\n";
            $content .= "const result = await {$this->toCamelCase($namespace)}.{$methodName}();\n";
        }

        $content .= <<<'BLADE'
</code-snippet>
@endverbatim
BLADE;

        return $content;
    }

    protected function toCamelCase(string $value): string
    {
        return lcfirst(Str::studly($value));
    }
}
