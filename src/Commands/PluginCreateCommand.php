<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class PluginCreateCommand extends Command
{
    protected $signature = 'native:plugin:create
                            {name? : The plugin name (e.g., haptics or vendor/plugin-haptics)}
                            {--namespace= : The plugin namespace}
                            {--path= : Custom output path}
                            {--force : Overwrite existing files}
                            {--with-boost : Generate Boost AI guidelines}';

    protected $description = 'Create a new NativePHP Mobile plugin';

    protected Filesystem $files;

    protected array $pluginData = [];

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        // Early validation of path if provided
        if ($this->option('path') && ! $this->option('force')) {
            if ($this->files->isDirectory($this->option('path'))) {
                $this->error("Directory {$this->option('path')} already exists. Use --force to overwrite.");

                return self::FAILURE;
            }
        }

        $this->gatherPluginInfo();

        // Check if path exists before creating structure (for interactive mode)
        if (! $this->validatePath()) {
            return self::FAILURE;
        }

        $this->createPluginStructure();
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    protected function validatePath(): bool
    {
        $path = $this->pluginData['path'];

        if ($this->files->isDirectory($path) && ! $this->option('force')) {
            $this->error("Directory {$path} already exists. Use --force to overwrite.");

            return false;
        }

        return true;
    }

    protected function gatherPluginInfo(): void
    {
        intro('Create a new NativePHP Mobile plugin');

        // Get plugin type
        $pluginType = select(
            label: 'What type of plugin are you creating?',
            options: [
                'system' => 'System / SDK plugin (wraps native APIs, camera, sensors, SDKs)',
                'ui' => 'UI Component plugin (custom native UI elements for Blade templates)',
            ],
            default: 'system',
        );

        // Get plugin name
        $name = $this->argument('name') ?? text(
            label: 'What is the plugin name?',
            placeholder: 'my-company/plugin-example',
            default: 'nativephp/plugin-example',
            hint: 'Use vendor/package format (e.g., nativephp/plugin-haptics)',
        );

        // Parse vendor/name format
        if (str_contains($name, '/')) {
            [$vendor, $package] = explode('/', $name, 2);
        } else {
            $vendor = text(
                label: 'What is the vendor/organization name?',
                default: 'nativephp',
            );
            $package = 'plugin-'.Str::kebab($name);
        }

        // Get namespace
        $defaultNamespace = Str::studly(str_replace('plugin-', '', $package));
        $namespace = $this->option('namespace') ?? text(
            label: 'What namespace should the plugin use?',
            default: $defaultNamespace,
            hint: 'This will be used for PHP classes and native code',
        );

        // Get description
        $description = text(
            label: 'Describe your plugin briefly',
            default: 'A NativePHP Mobile plugin',
        );

        // Get output path
        $path = $this->option('path');
        if (! $path) {
            $defaultPath = base_path("packages/{$vendor}/{$package}");
            $path = text(
                label: 'Where should the plugin be created?',
                default: $defaultPath,
            );
        }

        // Ask about Boost guidelines (skip if --with-boost flag was passed)
        $includeBoost = $this->option('with-boost') || confirm(
            label: 'Include Boost AI guidelines?',
            default: true,
            hint: 'Helps AI assistants understand how to use your plugin',
        );

        // Ask about installing AI agents
        $installAgents = confirm(
            label: 'Install AI agents for plugin development?',
            default: true,
            hint: 'Installs Kotlin/Swift expert agents and plugin helpers for Claude Code',
        );

        // Sanitize for Kotlin package names (no hyphens allowed)
        $kotlinSafeSlug = str_replace('-', '_', Str::lower(str_replace('plugin-', '', $package)));

        $this->pluginData = [
            'vendor' => $vendor,
            'package' => $package,
            'name' => "{$vendor}/{$package}",
            'namespace' => $namespace,
            'description' => $description,
            'path' => $path,
            'pluginType' => $pluginType,
            'composerType' => $pluginType === 'ui' ? 'nativephp-ui-plugin' : 'nativephp-plugin',
            'includeBoost' => $includeBoost,
            'installAgents' => $installAgents,
            'phpNamespace' => Str::studly($vendor).'\\'.Str::studly(str_replace('plugin-', '', $package)),
            'kotlinPackage' => 'com.'.Str::lower($vendor).'.plugins.'.$kotlinSafeSlug,
        ];
    }

    protected function createPluginStructure(): void
    {
        $path = $this->pluginData['path'];
        $isUiPlugin = $this->pluginData['pluginType'] === 'ui';

        $this->newLine();
        $typeLabel = $isUiPlugin ? 'UI component plugin' : 'system plugin';
        $this->components->twoColumnDetail("<fg=yellow>Creating {$typeLabel}</>", $path);
        $this->newLine();

        if ($isUiPlugin) {
            $this->createUiPluginStructure($path);
        } else {
            $this->createSystemPluginStructure($path);
        }

        // Common files
        $this->components->task('Creating README', fn () => $this->createReadme($path));
        $this->components->task('Creating .gitignore', fn () => $this->createGitignore($path));

        if ($this->pluginData['includeBoost']) {
            $this->components->task('Creating Boost AI guidelines', fn () => $this->createBoostGuidelines($path));
        }

        if ($this->pluginData['installAgents']) {
            $this->components->task('Installing AI agents', fn () => $this->installAgents());
        }
    }

    protected function createSystemPluginStructure(string $path): void
    {
        $directories = [
            '', '/src', '/src/Facades', '/src/Events', '/src/Commands',
            '/resources/android', '/resources/ios', '/resources/js', '/tests',
        ];

        foreach ($directories as $dir) {
            $this->files->ensureDirectoryExists($path.$dir);
        }

        $this->components->task('Creating composer.json', fn () => $this->createComposerJson($path));
        $this->components->task('Creating nativephp.json', fn () => $this->createNativephpJson($path));
        $this->components->task('Creating service provider', fn () => $this->createServiceProvider($path));
        $this->components->task('Creating facade', fn () => $this->createFacade($path));
        $this->components->task('Creating implementation class', fn () => $this->createImplementation($path));
        $this->components->task('Creating Android Kotlin functions', fn () => $this->createKotlinFunctions($path));
        $this->components->task('Creating iOS Swift functions', fn () => $this->createSwiftFunctions($path));
        $this->components->task('Creating JavaScript module', fn () => $this->createJavaScript($path));
        $this->components->task('Creating example event', fn () => $this->createExampleEvent($path));
        $this->components->task('Creating copy assets hook', fn () => $this->createCopyAssetsHookCommand($path));
        $this->components->task('Creating tests', fn () => $this->createTests($path));
    }

    protected function createUiPluginStructure(string $path): void
    {
        $directories = [
            '', '/src', '/src/Elements', '/src/Components',
            '/resources/android', '/resources/ios', '/tests',
        ];

        foreach ($directories as $dir) {
            $this->files->ensureDirectoryExists($path.$dir);
        }

        $this->components->task('Creating composer.json', fn () => $this->createComposerJson($path));
        $this->components->task('Creating nativephp.json', fn () => $this->createUiPluginManifest($path));
        $this->components->task('Creating service provider', fn () => $this->createUiServiceProvider($path));
        $this->components->task('Creating Element class', fn () => $this->createElementClass($path));
        $this->components->task('Creating Blade component', fn () => $this->createBladeComponentClass($path));
        $this->components->task('Creating Android Kotlin renderer', fn () => $this->createKotlinRenderer($path));
        $this->components->task('Creating iOS Swift renderer', fn () => $this->createSwiftRenderer($path));
        $this->components->task('Creating tests', fn () => $this->createUiPluginTests($path));
    }

    protected function installAgents(): void
    {
        $agents = [
            'plugin-writer',
            'plugin-docs-writer',
            'kotlin-android-expert',
            'swift-ios-expert',
            'js-bridge-expert',
        ];

        $sourcePath = dirname(__DIR__, 2).'/.claude/agents';
        $destDir = base_path('.claude/agents');

        $this->files->ensureDirectoryExists($destDir);

        foreach ($agents as $agent) {
            $source = "{$sourcePath}/{$agent}.md";
            $dest = "{$destDir}/{$agent}.md";

            if (! $this->files->exists($source)) {
                continue;
            }

            if ($this->files->exists($dest) && ! $this->option('force')) {
                continue;
            }

            $this->files->copy($source, $dest);
        }
    }

    protected function createComposerJson(string $path): void
    {
        $content = [
            'name' => $this->pluginData['name'],
            'description' => $this->pluginData['description'],
            'type' => $this->pluginData['composerType'],
            'license' => 'MIT',
            'authors' => [
                [
                    'name' => 'Your Name',
                    'email' => 'you@example.com',
                ],
            ],
            'require' => [
                'php' => '^8.2',
                'nativephp/mobile' => '^3.0',
            ],
            'autoload' => [
                'psr-4' => [
                    $this->pluginData['phpNamespace'].'\\' => 'src/',
                ],
            ],
            'extra' => [
                'laravel' => [
                    'providers' => [
                        $this->pluginData['phpNamespace'].'\\'.$this->pluginData['namespace'].'ServiceProvider',
                    ],
                ],
                'nativephp' => [
                    'manifest' => 'nativephp.json',
                ],
            ],
        ];

        $this->files->put(
            $path.'/composer.json',
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function createNativephpJson(string $path): void
    {
        $namespace = $this->pluginData['namespace'];
        $phpNamespace = $this->pluginData['phpNamespace'];
        $kotlinPackage = $this->pluginData['kotlinPackage'];

        // Build the hook command name following Laravel conventions
        $hookCommandName = 'nativephp:'.Str::kebab($namespace).':copy-assets';

        $content = [
            // Package identity
            'name' => $this->pluginData['name'],
            'version' => '1.0.0',
            'description' => $this->pluginData['description'],
            'namespace' => $namespace,

            // Marketplace metadata
            'keywords' => [],
            'category' => 'utilities',
            'license' => 'MIT',
            'pricing' => [
                'type' => 'free',
            ],
            'author' => [
                'name' => 'Your Name',
                'email' => 'you@example.com',
                'url' => '',
            ],
            'homepage' => '',
            'repository' => '',
            'funding' => [],

            // Platform support
            'platforms' => ['android', 'ios'],

            // Marketplace assets (relative paths)
            'icon' => 'resources/icon.png',
            'screenshots' => [],

            // Technical configuration
            'bridge_functions' => [
                [
                    'name' => $namespace.'.Execute',
                    'android' => $kotlinPackage.'.'.$namespace.'Functions.Execute',
                    'ios' => $namespace.'Functions.Execute',
                    'description' => 'Execute the plugin functionality',
                ],
                [
                    'name' => $namespace.'.GetStatus',
                    'android' => $kotlinPackage.'.'.$namespace.'Functions.GetStatus',
                    'ios' => $namespace.'Functions.GetStatus',
                    'description' => 'Get the current status',
                ],
            ],

            // Android-specific configuration
            'android' => [
                'min_version' => 21,
                'permissions' => [],
                'repositories' => [],
                'dependencies' => [
                    'implementation' => [],
                ],
                'activities' => [],
                'services' => [],
                'receivers' => [],
                'providers' => [],
            ],

            // iOS-specific configuration
            'ios' => [
                'min_version' => '15.0',
                'permissions' => [],
                'repositories' => [],
                'dependencies' => [
                    'swift_packages' => [],
                    'pods' => [],
                ],
            ],

            // Static assets to copy during build
            'assets' => [
                'android' => [],
                'ios' => [],
            ],

            // Plugin secrets (environment variables required from .env)
            // Example:
            // 'secrets' => [
            //     'MY_API_KEY' => [
            //         'description' => 'API key for the service',
            //         'required' => true,
            //     ],
            // ],
            'secrets' => [],

            'events' => [
                $phpNamespace.'\\Events\\'.$namespace.'Completed',
            ],
            'service_provider' => $phpNamespace.'\\'.$namespace.'ServiceProvider',
            'hooks' => [
                'copy_assets' => $hookCommandName,
            ],
        ];

        $this->files->put(
            $path.'/nativephp.json',
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function createServiceProvider(string $path): void
    {
        $namespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['namespace'];

        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Support\ServiceProvider;
use {$namespace}\\Commands\\CopyAssetsCommand;

class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        \$this->app->singleton({$name}::class, function () {
            return new {$name}();
        });
    }

    public function boot(): void
    {
        // Register plugin hook commands
        if (\$this->app->runningInConsole()) {
            \$this->commands([
                CopyAssetsCommand::class,
            ]);
        }
    }
}
PHP;

        $this->files->put($path."/src/{$name}ServiceProvider.php", $content);
    }

    protected function createFacade(string $path): void
    {
        $namespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['namespace'];

        $content = <<<PHP
<?php

namespace {$namespace}\\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed execute(array \$options = [])
 * @method static object|null getStatus()
 *
 * @see \\{$namespace}\\{$name}
 */
class {$name} extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \\{$namespace}\\{$name}::class;
    }
}
PHP;

        $this->files->put($path."/src/Facades/{$name}.php", $content);
    }

    protected function createImplementation(string $path): void
    {
        $namespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['namespace'];

        $content = <<<PHP
<?php

namespace {$namespace};

class {$name}
{
    /**
     * Execute the plugin functionality
     */
    public function execute(array \$options = []): mixed
    {
        if (function_exists('nativephp_call')) {
            \$result = nativephp_call('{$name}.Execute', json_encode(\$options));

            if (\$result) {
                \$decoded = json_decode(\$result);
                return \$decoded->data ?? null;
            }
        }

        return null;
    }

    /**
     * Get the current status
     */
    public function getStatus(): ?object
    {
        if (function_exists('nativephp_call')) {
            \$result = nativephp_call('{$name}.GetStatus', '{}');

            if (\$result) {
                \$decoded = json_decode(\$result);
                return \$decoded->data ?? null;
            }
        }

        return null;
    }
}
PHP;

        $this->files->put($path."/src/{$name}.php", $content);
    }

    protected function createKotlinFunctions(string $path): void
    {
        $namespace = $this->pluginData['namespace'];
        $kotlinPackage = $this->pluginData['kotlinPackage'];

        $content = <<<KOTLIN
package {$kotlinPackage}

import androidx.fragment.app.FragmentActivity
import android.content.Context
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse

object {$namespace}Functions {

    class Execute(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // TODO: Implement your native functionality here
            val option1 = parameters["option1"] as? String ?: ""

            // Example: Return success with data
            return BridgeResponse.success(mapOf(
                "result" to "executed",
                "option1" to option1
            ))
        }
    }

    class GetStatus(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // TODO: Return current status
            return BridgeResponse.success(mapOf(
                "status" to "ready",
                "version" to "1.0.0"
            ))
        }
    }
}
KOTLIN;

        $this->files->put($path."/resources/android/{$namespace}Functions.kt", $content);
    }

    protected function createSwiftFunctions(string $path): void
    {
        $namespace = $this->pluginData['namespace'];

        $content = <<<SWIFT
import Foundation

enum {$namespace}Functions {

    class Execute: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // TODO: Implement your native functionality here
            let option1 = parameters["option1"] as? String ?? ""

            // Example: Return success with data
            return BridgeResponse.success(data: [
                "result": "executed",
                "option1": option1
            ])
        }
    }

    class GetStatus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // TODO: Return current status
            return BridgeResponse.success(data: [
                "status": "ready",
                "version": "1.0.0"
            ])
        }
    }
}
SWIFT;

        $this->files->put($path."/resources/ios/{$namespace}Functions.swift", $content);
    }

    protected function createJavaScript(string $path): void
    {
        $namespace = $this->pluginData['namespace'];
        $camelName = lcfirst($namespace);

        $content = <<<JS
/**
 * {$namespace} Plugin for NativePHP Mobile
 *
 * @example
 * import { {$camelName} } from '@{$this->pluginData['vendor']}/{$this->pluginData['package']}';
 *
 * // Execute functionality
 * const result = await {$camelName}.execute({ option1: 'value' });
 *
 * // Get status
 * const status = await {$camelName}.getStatus();
 */

const baseUrl = '/_native/api/call';

/**
 * Internal bridge call function
 * @private
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    const nativeResponse = result.data;
    if (nativeResponse && nativeResponse.data !== undefined) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

/**
 * Execute the plugin functionality
 * @param {Object} options - Options to pass to the native function
 * @returns {Promise<any>}
 */
export async function execute(options = {}) {
    return bridgeCall('{$namespace}.Execute', options);
}

/**
 * Get the current status
 * @returns {Promise<Object>}
 */
export async function getStatus() {
    return bridgeCall('{$namespace}.GetStatus');
}

/**
 * {$namespace} namespace object
 */
export const {$camelName} = {
    execute,
    getStatus
};

export default {$camelName};
JS;

        $this->files->put($path."/resources/js/{$camelName}.js", $content);
    }

    protected function createExampleEvent(string $path): void
    {
        $namespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['namespace'];

        $content = <<<PHP
<?php

namespace {$namespace}\\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class {$name}Completed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string \$result,
        public ?string \$id = null
    ) {}
}
PHP;

        $this->files->put($path."/src/Events/{$name}Completed.php", $content);
    }

    protected function createCopyAssetsHookCommand(string $path): void
    {
        $namespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['namespace'];
        $kebabName = Str::kebab($name);

        $content = <<<PHP
<?php

namespace {$namespace}\\Commands;

use Native\\Mobile\\Plugins\\Commands\\NativePluginHookCommand;

/**
 * Copy assets hook command for {$name} plugin.
 *
 * This hook runs during the copy_assets phase of the build process.
 * Use it to copy ML models, binary files, or other assets that need
 * to be in specific locations in the native project.
 *
 * @see \\Native\\Mobile\\Plugins\\Commands\\NativePluginHookCommand
 */
class CopyAssetsCommand extends NativePluginHookCommand
{
    protected \$signature = 'nativephp:{$kebabName}:copy-assets';

    protected \$description = 'Copy assets for {$name} plugin';

    public function handle(): int
    {
        // Example: Copy different files based on platform
        if (\$this->isAndroid()) {
            \$this->copyAndroidAssets();
        }

        if (\$this->isIos()) {
            \$this->copyIosAssets();
        }

        return self::SUCCESS;
    }

    /**
     * Copy assets for Android build
     */
    protected function copyAndroidAssets(): void
    {
        // Example: Copy a TensorFlow Lite model to Android assets
        // \$this->copyToAndroidAssets('model.tflite', 'model.tflite');

        // Example: Download a model if not present locally
        // \$modelPath = \$this->pluginPath() . '/resources/model.tflite';
        // \$this->downloadIfMissing(
        //     'https://example.com/model.tflite',
        //     \$modelPath
        // );
        // \$this->copyToAndroidAssets('model.tflite', 'model.tflite');

        \$this->info('Android assets copied for {$name}');
    }

    /**
     * Copy assets for iOS build
     */
    protected function copyIosAssets(): void
    {
        // Example: Copy a Core ML model to iOS bundle
        // \$this->copyToIosBundle('model.mlmodelc', 'model.mlmodelc');

        \$this->info('iOS assets copied for {$name}');
    }
}
PHP;

        $this->files->put($path.'/src/Commands/CopyAssetsCommand.php', $content);
    }

    protected function createReadme(string $path): void
    {
        $name = $this->pluginData['namespace'];
        $packageName = $this->pluginData['name'];

        $content = <<<MD
# {$name} Plugin for NativePHP Mobile

{$this->pluginData['description']}

## Installation

```bash
composer require {$packageName}
```

## Usage

```php
use {$this->pluginData['phpNamespace']}\\Facades\\{$name};

// Execute functionality
\$result = {$name}::execute(['option1' => 'value']);

// Get status
\$status = {$name}::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:{$this->pluginData['phpNamespace']}\\Events\\{$name}Completed')]
public function handle{$name}Completed(\$result, \$id = null)
{
    // Handle the event
}
```

## License

MIT
MD;

        $this->files->put($path.'/README.md', $content);
    }

    protected function createGitignore(string $path): void
    {
        $content = <<<'GITIGNORE'
/vendor/
/node_modules/
.DS_Store
*.log
GITIGNORE;

        $this->files->put($path.'/.gitignore', $content);
    }

    protected function createBoostGuidelines(string $path): void
    {
        $namespace = $this->pluginData['namespace'];
        $phpNamespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['name'];
        $description = $this->pluginData['description'];
        $camelName = lcfirst($namespace);

        $guidelinesPath = $path.'/resources/boost/guidelines';
        $this->files->ensureDirectoryExists($guidelinesPath);

        $content = <<<BLADE
## {$name}

{$description}

### Installation

```bash
composer require {$name}
```

### PHP Usage (Livewire/Blade)

Use the `{$namespace}` facade:

@verbatim
<code-snippet name="Using {$namespace} Facade" lang="php">
use {$phpNamespace}\Facades\\{$namespace};

// Execute the plugin functionality
\$result = {$namespace}::execute(['option1' => 'value']);

// Get the current status
\$status = {$namespace}::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `{$namespace}::execute()`: Execute the plugin functionality
- `{$namespace}::getStatus()`: Get the current status

### Events

- `{$namespace}Completed`: Listen with `#[OnNative({$namespace}Completed::class)]`

@verbatim
<code-snippet name="Listening for {$namespace} Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use {$phpNamespace}\Events\\{$namespace}Completed;

#[OnNative({$namespace}Completed::class)]
public function handle{$namespace}Completed(\$result, \$id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using {$namespace} in JavaScript" lang="javascript">
import { {$camelName} } from '@{$name}';

// Execute the plugin functionality
const result = await {$camelName}.execute({ option1: 'value' });

// Get the current status
const status = await {$camelName}.getStatus();
</code-snippet>
@endverbatim
BLADE;

        $this->files->put($guidelinesPath.'/core.blade.php', $content);
    }

    protected function createTests(string $path): void
    {
        $namespace = $this->pluginData['namespace'];
        $phpNamespace = $this->pluginData['phpNamespace'];
        $kotlinPackage = $this->pluginData['kotlinPackage'];

        // Create Pest.php
        $pestContent = <<<'PHP'
<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()->in('.');
PHP;

        $this->files->put($path.'/tests/Pest.php', $pestContent);

        // Create the main plugin test file
        $testContent = <<<PHP
<?php

/**
 * Plugin validation tests for {$namespace}.
 *
 * Run with: ./vendor/bin/pest
 */

beforeEach(function () {
    \$this->pluginPath = dirname(__DIR__);
    \$this->manifestPath = \$this->pluginPath . '/nativephp.json';
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists(\$this->manifestPath))->toBeTrue();

        \$content = file_get_contents(\$this->manifestPath);
        \$manifest = json_decode(\$content, true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        expect(\$manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions']);
        expect(\$manifest['name'])->toBe('{$this->pluginData['name']}');
        expect(\$manifest['namespace'])->toBe('{$namespace}');
    });

    it('has valid bridge functions', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        expect(\$manifest['bridge_functions'])->toBeArray();

        foreach (\$manifest['bridge_functions'] as \$function) {
            expect(\$function)->toHaveKeys(['name']);
            expect(\$function)->toHaveAnyKeys(['android', 'ios']);
        }
    });

    it('has valid marketplace metadata', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        // Optional but recommended for marketplace
        if (isset(\$manifest['keywords'])) {
            expect(\$manifest['keywords'])->toBeArray();
        }

        if (isset(\$manifest['category'])) {
            expect(\$manifest['category'])->toBeString();
        }

        if (isset(\$manifest['platforms'])) {
            expect(\$manifest['platforms'])->toBeArray();
            foreach (\$manifest['platforms'] as \$platform) {
                expect(\$platform)->toBeIn(['android', 'ios']);
            }
        }
    });
});

describe('Native Code', function () {
    it('has Android Kotlin file', function () {
        \$kotlinFile = \$this->pluginPath . '/resources/android/{$namespace}Functions.kt';

        expect(file_exists(\$kotlinFile))->toBeTrue();

        \$content = file_get_contents(\$kotlinFile);
        expect(\$content)->toContain('package {$kotlinPackage}');
        expect(\$content)->toContain('object {$namespace}Functions');
        expect(\$content)->toContain('BridgeFunction');
    });

    it('has iOS Swift file', function () {
        \$swiftFile = \$this->pluginPath . '/resources/ios/{$namespace}Functions.swift';

        expect(file_exists(\$swiftFile))->toBeTrue();

        \$content = file_get_contents(\$swiftFile);
        expect(\$content)->toContain('enum {$namespace}Functions');
        expect(\$content)->toContain('BridgeFunction');
    });

    it('has matching bridge function classes in native code', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        \$kotlinFile = \$this->pluginPath . '/resources/android/{$namespace}Functions.kt';
        \$swiftFile = \$this->pluginPath . '/resources/ios/{$namespace}Functions.swift';

        \$kotlinContent = file_get_contents(\$kotlinFile);
        \$swiftContent = file_get_contents(\$swiftFile);

        foreach (\$manifest['bridge_functions'] as \$function) {
            // Extract class name from the function reference
            if (isset(\$function['android'])) {
                \$parts = explode('.', \$function['android']);
                \$className = end(\$parts);
                expect(\$kotlinContent)->toContain("class {\$className}");
            }

            if (isset(\$function['ios'])) {
                \$parts = explode('.', \$function['ios']);
                \$className = end(\$parts);
                expect(\$swiftContent)->toContain("class {\$className}");
            }
        }
    });
});

describe('PHP Classes', function () {
    it('has service provider', function () {
        \$file = \$this->pluginPath . '/src/{$namespace}ServiceProvider.php';
        expect(file_exists(\$file))->toBeTrue();

        \$content = file_get_contents(\$file);
        expect(\$content)->toContain('namespace {$phpNamespace}');
        expect(\$content)->toContain('class {$namespace}ServiceProvider');
    });

    it('has facade', function () {
        \$file = \$this->pluginPath . '/src/Facades/{$namespace}.php';
        expect(file_exists(\$file))->toBeTrue();

        \$content = file_get_contents(\$file);
        expect(\$content)->toContain('namespace {$phpNamespace}\\Facades');
        expect(\$content)->toContain('class {$namespace} extends Facade');
    });

    it('has main implementation class', function () {
        \$file = \$this->pluginPath . '/src/{$namespace}.php';
        expect(file_exists(\$file))->toBeTrue();

        \$content = file_get_contents(\$file);
        expect(\$content)->toContain('namespace {$phpNamespace}');
        expect(\$content)->toContain('class {$namespace}');
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json', function () {
        \$composerPath = \$this->pluginPath . '/composer.json';
        expect(file_exists(\$composerPath))->toBeTrue();

        \$content = file_get_contents(\$composerPath);
        \$composer = json_decode(\$content, true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect(\$composer['type'])->toBe('nativephp-plugin');
        expect(\$composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
    });
});

describe('Lifecycle Hooks', function () {
    it('has valid hooks configuration', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        if (isset(\$manifest['hooks'])) {
            expect(\$manifest['hooks'])->toBeArray();

            \$validHooks = ['pre_compile', 'post_compile', 'copy_assets', 'post_build'];
            foreach (array_keys(\$manifest['hooks']) as \$hook) {
                expect(\$hook)->toBeIn(\$validHooks);
            }
        }
    });

    it('has copy_assets hook command', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        expect(\$manifest['hooks']['copy_assets'] ?? null)->not->toBeNull();

        \$commandFile = \$this->pluginPath . '/src/Commands/CopyAssetsCommand.php';
        expect(file_exists(\$commandFile))->toBeTrue();
    });

    it('copy_assets command extends NativePluginHookCommand', function () {
        \$commandFile = \$this->pluginPath . '/src/Commands/CopyAssetsCommand.php';
        \$content = file_get_contents(\$commandFile);

        expect(\$content)->toContain('extends NativePluginHookCommand');
        expect(\$content)->toContain('use Native\\Mobile\\Plugins\\Commands\\NativePluginHookCommand');
    });

    it('copy_assets command has correct signature', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);
        \$expectedSignature = \$manifest['hooks']['copy_assets'];

        \$commandFile = \$this->pluginPath . '/src/Commands/CopyAssetsCommand.php';
        \$content = file_get_contents(\$commandFile);

        expect(\$content)->toContain('\$signature = \'' . \$expectedSignature . '\'');
    });

    it('copy_assets command has platform-specific methods', function () {
        \$commandFile = \$this->pluginPath . '/src/Commands/CopyAssetsCommand.php';
        \$content = file_get_contents(\$commandFile);

        // Should check for platform
        expect(\$content)->toContain('\$this->isAndroid()');
        expect(\$content)->toContain('\$this->isIos()');
    });

    it('has valid assets configuration', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        // Assets are at top level with android/ios nested inside
        if (isset(\$manifest['assets'])) {
            expect(\$manifest['assets'])->toBeArray();

            if (isset(\$manifest['assets']['android'])) {
                expect(\$manifest['assets']['android'])->toBeArray();
            }

            if (isset(\$manifest['assets']['ios'])) {
                expect(\$manifest['assets']['ios'])->toBeArray();
            }
        }
    });
});
PHP;

        $this->files->put($path.'/tests/PluginTest.php', $testContent);

        // Add pest to composer.json require-dev
        $composerPath = $path.'/composer.json';
        $composer = json_decode($this->files->get($composerPath), true);
        $composer['require-dev'] = [
            'pestphp/pest' => '^3.0',
        ];
        $composer['scripts'] = [
            'test' => 'pest',
        ];
        $composer['config'] = [
            'allow-plugins' => [
                'pestphp/pest-plugin' => true,
            ],
        ];
        $this->files->put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function createUiPluginManifest(string $path): void
    {
        $namespace = $this->pluginData['namespace'];
        $phpNamespace = $this->pluginData['phpNamespace'];
        $kotlinPackage = $this->pluginData['kotlinPackage'];

        // Build a dot-namespaced component type from namespace
        $componentType = Str::camel($namespace).'.default';

        $content = [
            'name' => $this->pluginData['name'],
            'version' => '1.0.0',
            'description' => $this->pluginData['description'],
            'namespace' => $namespace,

            'platforms' => ['android', 'ios'],

            'components' => [
                [
                    'type' => $componentType,
                    'element' => $phpNamespace.'\\Elements\\'.$namespace,
                    'blade' => $phpNamespace.'\\Components\\'.$namespace,
                    'android_renderer' => $kotlinPackage.'.ui.'.$namespace.'Renderer',
                    'ios_renderer' => $namespace.'Renderer',
                    'self_closing' => true,
                ],
            ],

            'android' => [
                'dependencies' => [
                    'implementation' => [],
                ],
            ],

            'ios' => [
                'dependencies' => [
                    'swift_packages' => [],
                    'pods' => [],
                ],
            ],
        ];

        $this->files->put(
            $path.'/nativephp.json',
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function createUiServiceProvider(string $path): void
    {
        $namespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['namespace'];

        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Support\ServiceProvider;

class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
PHP;

        $this->files->put($path."/src/{$name}ServiceProvider.php", $content);
    }

    protected function createElementClass(string $path): void
    {
        $namespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['namespace'];
        $componentType = Str::camel($name).'.default';

        $content = <<<PHP
<?php

namespace {$namespace}\\Elements;

use Native\\Mobile\\Edge\\Element;
use Native\\Mobile\\Edge\\CallbackRegistry;

class {$name} extends Element
{
    protected string \$type = '{$componentType}';

    protected array \$componentProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function value(mixed \$value): static
    {
        \$this->componentProps['value'] = \$value;

        return \$this;
    }

    public function onChange(string \$method): static
    {
        \$this->componentProps['on_change'] = \$method;

        return \$this;
    }

    protected function resolveProps(CallbackRegistry \$registry): array
    {
        \$props = \$this->componentProps;

        if (isset(\$props['on_change'])) {
            \$props['on_change'] = \$registry->register(\$props['on_change']);
        }

        return \$props;
    }
}
PHP;

        $this->files->put($path."/src/Elements/{$name}.php", $content);
    }

    protected function createBladeComponentClass(string $path): void
    {
        $namespace = $this->pluginData['phpNamespace'];
        $name = $this->pluginData['namespace'];
        $componentType = Str::camel($name).'.default';

        $content = <<<PHP
<?php

namespace {$namespace}\\Components;

use Native\\Mobile\\Edge\\Components\\Native\\NativeBladeComponent;

class {$name} extends NativeBladeComponent
{
    protected bool \$isSelfClosing = true;

    protected function elementType(): string
    {
        return '{$componentType}';
    }
}
PHP;

        $this->files->put($path."/src/Components/{$name}.php", $content);
    }

    protected function createKotlinRenderer(string $path): void
    {
        $namespace = $this->pluginData['namespace'];
        $kotlinPackage = $this->pluginData['kotlinPackage'];

        $content = <<<KOTLIN
package {$kotlinPackage}.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.nativephp.mobile.ui.nativerender.NativeUINode
import com.nativephp.mobile.ui.nativerender.NativeUIBridge

object {$namespace}Renderer {
    @Composable
    fun Render(node: NativeUINode, modifier: Modifier) {
        val p = node.props

        // Read props from the wire format
        // val myValue = p.getString("value", "")
        // val onChangeCb = p.getCallbackId("on_change")

        // TODO: Replace with your custom Compose UI
        Box(modifier = modifier.padding(16.dp)) {
            Text(text = "{$namespace} Component")
        }

        // To send events back to PHP:
        // NativeUIBridge.sendTextChangeEvent(onChangeCb, newValue)
    }
}
KOTLIN;

        $this->files->put($path."/resources/android/{$namespace}Renderer.kt", $content);
    }

    protected function createSwiftRenderer(string $path): void
    {
        $namespace = $this->pluginData['namespace'];

        $content = <<<SWIFT
import SwiftUI

struct {$namespace}Renderer: View {
    let node: NativeUINode

    var body: some View {
        // Read props from the wire format
        // let myValue = node.props.getString("value", default: "")

        // TODO: Replace with your custom SwiftUI view
        Text("{$namespace} Component")
            .padding()
    }
}
SWIFT;

        $this->files->put($path."/resources/ios/{$namespace}Renderer.swift", $content);
    }

    protected function createUiPluginTests(string $path): void
    {
        $namespace = $this->pluginData['namespace'];
        $phpNamespace = $this->pluginData['phpNamespace'];

        // Pest.php
        $pestContent = <<<'PHP'
<?php

uses()->in('.');
PHP;

        $this->files->put($path.'/tests/Pest.php', $pestContent);

        $componentType = Str::camel($namespace).'.default';

        $testContent = <<<PHP
<?php

beforeEach(function () {
    \$this->pluginPath = dirname(__DIR__);
    \$this->manifestPath = \$this->pluginPath . '/nativephp.json';
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists(\$this->manifestPath))->toBeTrue();

        \$content = file_get_contents(\$this->manifestPath);
        \$manifest = json_decode(\$content, true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        expect(\$manifest)->toHaveKeys(['namespace', 'components']);
        expect(\$manifest['namespace'])->toBe('{$namespace}');
    });

    it('has valid components', function () {
        \$manifest = json_decode(file_get_contents(\$this->manifestPath), true);

        expect(\$manifest['components'])->toBeArray()->not->toBeEmpty();

        foreach (\$manifest['components'] as \$component) {
            expect(\$component)->toHaveKeys(['type', 'element', 'blade']);
            // Component types must be dot-namespaced
            expect(\$component['type'])->toContain('.');
            // At least one platform renderer
            expect(
                !empty(\$component['android_renderer']) || !empty(\$component['ios_renderer'])
            )->toBeTrue();
        }
    });
});

describe('PHP Classes', function () {
    it('has Element class', function () {
        \$file = \$this->pluginPath . '/src/Elements/{$namespace}.php';
        expect(file_exists(\$file))->toBeTrue();

        \$content = file_get_contents(\$file);
        expect(\$content)->toContain('extends Element');
        expect(\$content)->toContain("'{$componentType}'");
    });

    it('has Blade component class', function () {
        \$file = \$this->pluginPath . '/src/Components/{$namespace}.php';
        expect(file_exists(\$file))->toBeTrue();

        \$content = file_get_contents(\$file);
        expect(\$content)->toContain('extends NativeBladeComponent');
        expect(\$content)->toContain("'{$componentType}'");
    });

    it('has service provider', function () {
        \$file = \$this->pluginPath . '/src/{$namespace}ServiceProvider.php';
        expect(file_exists(\$file))->toBeTrue();
    });
});

describe('Native Renderers', function () {
    it('has Android Kotlin renderer', function () {
        \$file = \$this->pluginPath . '/resources/android/{$namespace}Renderer.kt';
        expect(file_exists(\$file))->toBeTrue();

        \$content = file_get_contents(\$file);
        expect(\$content)->toContain('object {$namespace}Renderer');
        expect(\$content)->toContain('@Composable');
        expect(\$content)->toContain('fun Render(');
    });

    it('has iOS Swift renderer', function () {
        \$file = \$this->pluginPath . '/resources/ios/{$namespace}Renderer.swift';
        expect(file_exists(\$file))->toBeTrue();

        \$content = file_get_contents(\$file);
        expect(\$content)->toContain('{$namespace}Renderer');
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json with UI plugin type', function () {
        \$composerPath = \$this->pluginPath . '/composer.json';
        expect(file_exists(\$composerPath))->toBeTrue();

        \$composer = json_decode(file_get_contents(\$composerPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect(\$composer['type'])->toBe('nativephp-ui-plugin');
    });
});
PHP;

        $this->files->put($path.'/tests/PluginTest.php', $testContent);

        // Add pest to composer.json
        $composerPath = $path.'/composer.json';
        $composer = json_decode($this->files->get($composerPath), true);
        $composer['require-dev'] = ['pestphp/pest' => '^3.0'];
        $composer['scripts'] = ['test' => 'pest'];
        $composer['config'] = ['allow-plugins' => ['pestphp/pest-plugin' => true]];
        $this->files->put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function displayNextSteps(): void
    {
        outro('Plugin created successfully!');

        $isUiPlugin = $this->pluginData['pluginType'] === 'ui';
        $namespace = $this->pluginData['namespace'];

        $this->newLine();
        $this->components->twoColumnDetail('Next steps', '');

        if ($isUiPlugin) {
            $componentType = Str::camel($namespace).'.default';
            $bladeTag = 'native:'.str_replace(['.', '_'], '-', $componentType);

            $this->components->bulletList([
                'Implement the Element class in:',
                "  <comment>src/Elements/{$namespace}.php</comment>",
                'Implement native renderers in:',
                "  <comment>resources/android/{$namespace}Renderer.kt</comment>",
                "  <comment>resources/ios/{$namespace}Renderer.swift</comment>",
                'Edit <comment>nativephp.json</comment> to add dependencies',
                "Use in Blade: <info><{$bladeTag} /></info>",
            ]);
        } else {
            $this->components->bulletList([
                'Implement native functions in:',
                "  <comment>resources/android/{$namespace}Functions.kt</comment>",
                "  <comment>resources/ios/{$namespace}Functions.swift</comment>",
                'Edit <comment>nativephp.json</comment> to add permissions and dependencies',
                'Customize the copy_assets hook in:',
                '  <comment>src/Commands/CopyAssetsCommand.php</comment>',
            ]);

            $this->newLine();
            $this->components->twoColumnDetail('Available hooks', '');
            $this->components->bulletList([
                '<info>pre_compile</info> - Before native code is compiled',
                '<info>post_compile</info> - After native code compilation',
                '<info>copy_assets</info> - Copy ML models, binary files, etc.',
                '<info>post_build</info> - After native build completes',
            ]);
        }

        if (! $this->pluginData['includeBoost']) {
            $this->newLine();
            $this->components->twoColumnDetail('Boost AI Guidelines', '');
            $this->line('  Add AI guidelines for your plugin with:');
            $this->line('  <comment>php artisan native:plugin:boost '.$this->pluginData['path'].'</comment>');
        }

        $this->newLine();
        $this->components->twoColumnDetail('To install in your app', '');
        $this->line('  Add to composer.json <comment>"repositories"</comment> section:');
        $this->newLine();
        $this->line('  <info>{"type": "path", "url": "'.$this->pluginData['path'].'"}</info>');
        $this->newLine();
        $this->line('  Then run:');
        $this->line('  <comment>composer require '.$this->pluginData['name'].'</comment>');
        $this->newLine();
        $this->line('  Verify: <comment>php artisan native:plugin:list</comment>');
        $this->line('  Build:  <comment>php artisan native:run</comment>');
    }
}
