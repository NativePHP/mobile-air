<?php

namespace Native\Mobile\Plugins\Compilers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Native\Mobile\Exceptions\PluginConflictException;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginHookRunner;
use Native\Mobile\Plugins\PluginRegistry;
use Native\Mobile\Support\Stub;

class AndroidPluginCompiler
{
    protected string $androidProjectPath;

    protected string $generatedPath;

    protected array $generatedFiles = [];

    protected ?string $appId = null;

    protected ?PluginHookRunner $hookRunner = null;

    protected $output = null;

    protected array $config = [];

    public function __construct(
        protected Filesystem $files,
        protected PluginRegistry $registry,
        protected string $basePath
    ) {
        $this->androidProjectPath = $basePath.'/android';

        // Detect current app ID from build.gradle.kts (after prepareAndroidBuild has updated it)
        $this->appId = $this->detectCurrentAppId() ?? 'com.nativephp.mobile';

        // Plugin registration always goes in the core NativePHP package
        $this->generatedPath = $this->androidProjectPath.'/app/src/main/java/com/nativephp/mobile/bridge/plugins';
    }

    /**
     * Set the output interface for logging
     */
    public function setOutput($output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Output a warning message
     */
    protected function warn(string $message): void
    {
        if ($this->output) {
            $this->output->warn($message);
        }
    }

    /**
     * Set the app ID for hooks context (overrides detected)
     */
    public function setAppId(string $appId): self
    {
        $this->appId = $appId;
        // Note: generatedPath stays at com/nativephp/mobile/bridge/plugins/
        // Plugin registration is always in the core NativePHP package

        return $this;
    }

    /**
     * Set the build config for hooks context
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Get the hook runner instance
     */
    protected function getHookRunner(): PluginHookRunner
    {
        if ($this->hookRunner === null) {
            $this->hookRunner = new PluginHookRunner(
                platform: 'android',
                buildPath: $this->androidProjectPath,
                appId: $this->appId,
                config: $this->config,
                plugins: $this->registry->all(),
                output: $this->output
            );
        }

        return $this->hookRunner;
    }

    /**
     * Detect current app ID from build.gradle.kts
     */
    protected function detectCurrentAppId(): ?string
    {
        $gradlePath = $this->androidProjectPath.'/app/build.gradle.kts';

        if (! $this->files->exists($gradlePath)) {
            return null;
        }

        $contents = $this->files->get($gradlePath);

        if (preg_match('/applicationId\s*=\s*"([^"]+)"/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Compile all plugins for Android
     */
    public function compile(): void
    {
        $this->generatedFiles = [];

        // Check for plugin conflicts before compiling
        $conflicts = $this->registry->detectConflicts();
        if (! empty($conflicts)) {
            throw new PluginConflictException($conflicts);
        }

        $allPlugins = $this->registry->all();
        $hookRunner = $this->getHookRunner();

        // Run pre-compile hooks
        $hookRunner->runPreCompileHooks();

        if ($allPlugins->isEmpty()) {
            $this->generateEmptyRegistration();

            return;
        }

        // Check if there are any plugins with Android bridge functions
        $hasAndroidFunctions = $allPlugins->filter(function (Plugin $p) {
            foreach ($p->getBridgeFunctions() as $function) {
                if (! empty($function['android'])) {
                    return true;
                }
            }

            return false;
        })->isNotEmpty();

        // Ensure generated directory exists
        $this->files->ensureDirectoryExists($this->generatedPath);

        // Copy plugin source files for plugins that have Android code
        $allPlugins->filter(fn (Plugin $p) => $p->hasAndroidCode())
            ->each(fn (Plugin $plugin) => $this->copyPluginSources($plugin));

        // Generate the registration file
        if ($hasAndroidFunctions) {
            $this->generateBridgeFunctionRegistration($allPlugins);
        } else {
            $this->generateEmptyRegistration();
        }

        // Merge AndroidManifest entries (even if no bridge functions)
        $this->mergeManifestEntries($allPlugins);

        // Add Gradle dependencies (even if no bridge functions)
        $this->addGradleDependencies($allPlugins);

        // Add Maven repositories from plugins
        $this->addGradleRepositories($allPlugins);

        // Copy manifest-declared assets
        $hookRunner->copyManifestAssets();

        // Run copy-assets hooks
        $hookRunner->runCopyAssetsHooks();

        // Run post-compile hooks
        $hookRunner->runPostCompileHooks();
    }

    /**
     * Copy Kotlin source files from plugin to Android project
     *
     * Files are placed at directories matching their package declaration.
     */
    protected function copyPluginSources(Plugin $plugin): void
    {
        $sourcePath = $plugin->getAndroidSourcePath();

        if (! $this->files->isDirectory($sourcePath)) {
            return;
        }

        $javaBasePath = $this->androidProjectPath.'/app/src/main/java';

        // Copy all Kotlin files
        $files = $this->files->allFiles($sourcePath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'kt') {
                continue;
            }

            $content = $this->files->get($file->getPathname());

            // Extract package declaration
            $package = $this->extractPackageFromContent($content);

            if ($package === null) {
                // Warn about missing package declaration
                $this->warn(
                    "Plugin '{$plugin->name}': {$file->getFilename()} has no package declaration. ".
                    "Plugins should declare packages like 'package com.yourvendor.pluginname'"
                );
                // Fallback: use sanitized namespace under bridge/plugins if no package found
                $safeNamespace = $this->sanitizeKotlinName($plugin->getNamespace());
                $destination = $this->generatedPath.'/'.$safeNamespace.'/'.$file->getFilename();
            } else {
                // Place file at path matching its package declaration
                $packagePath = str_replace('.', '/', $package);
                $destination = $javaBasePath.'/'.$packagePath.'/'.$file->getFilename();
            }

            $this->files->ensureDirectoryExists(dirname($destination));
            $this->files->put($destination, $content);
            $this->generatedFiles[] = $destination;
        }
    }

    /**
     * Extract package declaration from Kotlin file content
     */
    protected function extractPackageFromContent(string $content): ?string
    {
        if (preg_match('/^package\s+([\w.]+)/m', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Sanitize a name for Kotlin (replace hyphens with underscores)
     */
    protected function sanitizeKotlinName(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    /**
     * Generate PluginBridgeFunctionRegistration.kt
     */
    protected function generateBridgeFunctionRegistration(Collection $plugins): void
    {
        $registrations = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->getBridgeFunctions() as $function) {
                if (empty($function['android'])) {
                    continue;
                }

                $registrations[] = [
                    'name' => $function['name'],
                    'class' => $function['android'],
                    'plugin' => $plugin->name,
                    'params' => $function['android_params'] ?? ['activity'],
                ];
            }
        }

        $content = $this->renderRegistrationTemplate($registrations);
        $path = $this->generatedPath.'/PluginBridgeFunctionRegistration.kt';

        $this->files->put($path, $content);
        $this->generatedFiles[] = $path;
    }

    /**
     * Render the Kotlin registration file
     */
    protected function renderRegistrationTemplate(array $registrations): string
    {
        // Build imports from the android class paths in nativephp.json
        $imports = collect($registrations)
            ->pluck('class')
            ->map(fn ($class) => $this->extractImportPath($class))
            ->unique()
            ->sort()
            ->map(fn ($package) => "import {$package}")
            ->implode("\n");

        $registerCalls = collect($registrations)
            ->map(function ($reg) {
                $className = $this->extractClassName($reg['class']);
                $params = $reg['params'] ?? ['activity'];
                $paramString = $this->determineParameter($params);

                return "    // Plugin: {$reg['plugin']}\n    registry.register(\"{$reg['name']}\", {$className}({$paramString}))";
            })
            ->implode("\n\n");

        return Stub::make('android/PluginBridgeFunctionRegistration.kt.stub')
            ->replaceAll([
                'IMPORTS' => $imports,
                'REGISTRATIONS' => $registerCalls,
            ])
            ->render();
    }

    /**
     * Extract import path from full class reference (package.Class.Method -> package.Class)
     */
    protected function extractImportPath(string $classPath): string
    {
        $parts = explode('.', $classPath);
        array_pop($parts); // Remove method name

        return implode('.', $parts);
    }

    /**
     * Generate empty registration when no plugins
     */
    protected function generateEmptyRegistration(): void
    {
        $this->files->ensureDirectoryExists($this->generatedPath);

        $content = Stub::make('android/PluginBridgeFunctionRegistration.empty.kt.stub')->render();

        $path = $this->generatedPath.'/PluginBridgeFunctionRegistration.kt';
        $this->files->put($path, $content);
        $this->generatedFiles[] = $path;
    }

    /**
     * Merge plugin AndroidManifest.xml entries into main manifest
     */
    protected function mergeManifestEntries(Collection $plugins): void
    {
        $mainManifestPath = $this->androidProjectPath.'/app/src/main/AndroidManifest.xml';
        $mainManifest = $this->files->get($mainManifestPath);

        $permissionsToAdd = [];
        $featuresToAdd = [];
        $applicationEntries = [];

        foreach ($plugins as $plugin) {
            // Always add permissions from nativephp.json
            foreach ($plugin->getAndroidPermissions() as $permission) {
                $permissionsToAdd[] = $permission;
            }

            // Add features from nativephp.json
            foreach ($plugin->getAndroidFeatures() as $feature) {
                $featuresToAdd[] = $feature;
            }

            // Check for XML manifest file (legacy approach)
            $pluginManifestPath = $plugin->path.'/resources/android/AndroidManifest.xml';
            if ($this->files->exists($pluginManifestPath)) {
                $pluginManifest = $this->files->get($pluginManifestPath);
                $extracted = $this->extractManifestEntries($pluginManifest);
                $permissionsToAdd = array_merge($permissionsToAdd, $extracted['permissions']);
                $applicationEntries = array_merge($applicationEntries, $extracted['application']);
            }

            // Process JSON-based manifest entries from nativephp.json
            $jsonManifest = $plugin->getAndroidManifest();
            if (! empty($jsonManifest)) {
                $jsonEntries = $this->buildManifestEntriesFromJson($jsonManifest, $plugin);
                $applicationEntries = array_merge($applicationEntries, $jsonEntries);
            }
        }

        // Add permissions that don't already exist
        $mainManifest = $this->injectPermissions($mainManifest, array_unique($permissionsToAdd));

        // Add features that don't already exist
        $mainManifest = $this->injectFeatures($mainManifest, $featuresToAdd);

        // Add application entries
        $mainManifest = $this->injectApplicationEntries($mainManifest, $applicationEntries);

        $this->files->put($mainManifestPath, $mainManifest);
    }

    /**
     * Build XML manifest entries from JSON manifest config
     */
    protected function buildManifestEntriesFromJson(array $manifest, Plugin $plugin): array
    {
        $entries = [];

        // Process activities
        foreach ($manifest['activities'] ?? [] as $activity) {
            $entries[] = $this->buildActivityEntry($activity, $plugin);
        }

        // Process services
        foreach ($manifest['services'] ?? [] as $service) {
            $entries[] = $this->buildServiceEntry($service, $plugin);
        }

        // Process receivers
        foreach ($manifest['receivers'] ?? [] as $receiver) {
            $entries[] = $this->buildReceiverEntry($receiver, $plugin);
        }

        // Process providers
        foreach ($manifest['providers'] ?? [] as $provider) {
            $entries[] = $this->buildProviderEntry($provider, $plugin);
        }

        // Process meta-data
        foreach ($manifest['meta_data'] ?? [] as $metaData) {
            $entries[] = $this->buildMetaDataEntry($metaData);
        }

        return $entries;
    }

    /**
     * Build a meta-data XML entry
     */
    protected function buildMetaDataEntry(array $metaData): string
    {
        $name = $metaData['name'];
        $value = $metaData['value'];

        // Handle different value types
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return "<meta-data android:name=\"{$name}\" android:value=\"{$value}\" />";
    }

    /**
     * Resolve component name - replace relative names with full package path
     */
    protected function resolveComponentName(string $name, Plugin $plugin): string
    {
        // If starts with '.', it's relative to the plugin's package
        if (str_starts_with($name, '.')) {
            $basePackage = $this->detectPluginBasePackage($plugin);
            if ($basePackage === null) {
                throw new \InvalidArgumentException(
                    "Plugin '{$plugin->name}' uses relative component name '{$name}' but has no package declaration in its Kotlin files. ".
                    'Either add a package declaration to your Kotlin files or use a fully-qualified component name.'
                );
            }

            return "{$basePackage}{$name}";
        }

        return $name;
    }

    /**
     * Detect the base package from a plugin's Kotlin source files
     */
    protected function detectPluginBasePackage(Plugin $plugin): ?string
    {
        $sourcePath = $plugin->getAndroidSourcePath();

        if (! $this->files->isDirectory($sourcePath)) {
            return null;
        }

        // Find first Kotlin file with a package declaration
        foreach ($this->files->allFiles($sourcePath) as $file) {
            if ($file->getExtension() !== 'kt') {
                continue;
            }

            $content = $this->files->get($file->getPathname());
            if (preg_match('/^package\s+([\w.]+)/m', $content, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Build an activity XML entry
     */
    protected function buildActivityEntry(array $activity, Plugin $plugin): string
    {
        $name = $this->resolveComponentName($activity['name'], $plugin);
        $attrs = ["android:name=\"{$name}\""];

        if (isset($activity['theme'])) {
            $attrs[] = "android:theme=\"{$activity['theme']}\"";
        }
        if (isset($activity['screenOrientation'])) {
            $attrs[] = "android:screenOrientation=\"{$activity['screenOrientation']}\"";
        }
        if (isset($activity['exported'])) {
            $attrs[] = 'android:exported="'.($activity['exported'] ? 'true' : 'false').'"';
        }
        if (isset($activity['launchMode'])) {
            $attrs[] = "android:launchMode=\"{$activity['launchMode']}\"";
        }
        if (isset($activity['configChanges'])) {
            $attrs[] = "android:configChanges=\"{$activity['configChanges']}\"";
        }

        $attrString = implode("\n            ", $attrs);

        // Check for intent filters (support both snake_case and kebab-case)
        $intentFilters = $activity['intent_filters'] ?? $activity['intent-filters'] ?? [];
        if (! empty($intentFilters)) {
            $filters = $this->buildIntentFilters($intentFilters);

            return "<activity\n            {$attrString}>\n{$filters}        </activity>";
        }

        return "<activity\n            {$attrString} />";
    }

    /**
     * Build a service XML entry
     */
    protected function buildServiceEntry(array $service, Plugin $plugin): string
    {
        $name = $this->resolveComponentName($service['name'], $plugin);
        $attrs = ["android:name=\"{$name}\""];

        if (isset($service['exported'])) {
            $attrs[] = 'android:exported="'.($service['exported'] ? 'true' : 'false').'"';
        }
        if (isset($service['permission'])) {
            $attrs[] = "android:permission=\"{$service['permission']}\"";
        }
        if (isset($service['foregroundServiceType'])) {
            $type = $service['foregroundServiceType'];
            if (is_array($type)) {
                $type = implode('|', $type);
            }
            $attrs[] = "android:foregroundServiceType=\"{$type}\"";
        }

        $attrString = implode("\n            ", $attrs);

        // Build nested content (intent-filters and meta-data)
        $nestedContent = '';
        
        // Support both snake_case and kebab-case for intent filters
        $intentFilters = $service['intent_filters'] ?? $service['intent-filters'] ?? [];
        if (!empty($intentFilters)) {
            $nestedContent .= $this->buildIntentFilters($intentFilters);
        }
        
        // Add meta-data support at service level
        $metaData = $service['meta_data'] ?? $service['meta-data'] ?? [];
        if (!empty($metaData)) {
            $nestedContent .= $this->buildComponentMetaData($metaData);
        }

        if (!empty($nestedContent)) {
            return "<service\n            {$attrString}>\n{$nestedContent}        </service>";
        }

        return "<service\n            {$attrString} />";
    }

    /**
     * Build meta-data XML entries for use inside manifest components
     */
    protected function buildComponentMetaData(array $metaDataEntries): string
    {
        $xml = '';
        
        foreach ($metaDataEntries as $metaData) {
            $name = $metaData['name'];
            $value = $metaData['value'] ?? null;
            $resource = $metaData['resource'] ?? null;
            
            if ($resource !== null) {
                $xml .= "            <meta-data android:name=\"{$name}\" android:resource=\"{$resource}\" />\n";
            } elseif ($value !== null) {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $xml .= "            <meta-data android:name=\"{$name}\" android:value=\"{$value}\" />\n";
            }
        }
        
        return $xml;
    }

    /**
     * Build a receiver XML entry
     */
    protected function buildReceiverEntry(array $receiver, Plugin $plugin): string
    {
        $name = $this->resolveComponentName($receiver['name'], $plugin);
        $attrs = ["android:name=\"{$name}\""];

        if (isset($receiver['exported'])) {
            $attrs[] = 'android:exported="'.($receiver['exported'] ? 'true' : 'false').'"';
        }
        if (isset($receiver['permission'])) {
            $attrs[] = "android:permission=\"{$receiver['permission']}\"";
        }

        $attrString = implode("\n            ", $attrs);

        // Support both snake_case and kebab-case
        $intentFilters = $receiver['intent_filters'] ?? $receiver['intent-filters'] ?? [];
        if (! empty($intentFilters)) {
            $filters = $this->buildIntentFilters($intentFilters);

            return "<receiver\n            {$attrString}>\n{$filters}        </receiver>";
        }

        return "<receiver\n            {$attrString} />";
    }

    /**
     * Build a provider XML entry
     */
    protected function buildProviderEntry(array $provider, Plugin $plugin): string
    {
        $name = $this->resolveComponentName($provider['name'], $plugin);
        $attrs = ["android:name=\"{$name}\""];

        if (isset($provider['authorities'])) {
            $authorities = str_replace('${applicationId}', $this->appId, $provider['authorities']);
            $attrs[] = "android:authorities=\"{$authorities}\"";
        }
        if (isset($provider['exported'])) {
            $attrs[] = 'android:exported="'.($provider['exported'] ? 'true' : 'false').'"';
        }
        if (isset($provider['grantUriPermissions'])) {
            $attrs[] = 'android:grantUriPermissions="'.($provider['grantUriPermissions'] ? 'true' : 'false').'"';
        }

        $attrString = implode("\n            ", $attrs);

        return "<provider\n            {$attrString} />";
    }

    /**
     * Build intent filter XML blocks
     */
    protected function buildIntentFilters(array $filters): string
    {
        $xml = '';

        foreach ($filters as $filter) {
            $xml .= "            <intent-filter>\n";

            if (isset($filter['action'])) {
                $actions = is_array($filter['action']) ? $filter['action'] : [$filter['action']];
                foreach ($actions as $action) {
                    $xml .= "                <action android:name=\"{$action}\" />\n";
                }
            }

            if (isset($filter['category'])) {
                $categories = is_array($filter['category']) ? $filter['category'] : [$filter['category']];
                foreach ($categories as $category) {
                    $xml .= "                <category android:name=\"{$category}\" />\n";
                }
            }

            if (isset($filter['data'])) {
                $dataAttrs = [];
                foreach ($filter['data'] as $key => $value) {
                    $dataAttrs[] = "android:{$key}=\"{$value}\"";
                }
                $xml .= '                <data '.implode(' ', $dataAttrs)." />\n";
            }

            $xml .= "            </intent-filter>\n";
        }

        return $xml;
    }

    /**
     * Extract permissions and application entries from manifest XML
     */
    protected function extractManifestEntries(string $xml): array
    {
        $permissions = [];
        $application = [];

        // Extract uses-permission entries
        preg_match_all('/<uses-permission[^>]+>/s', $xml, $matches);
        $permissions = $matches[0] ?? [];

        // Extract application children (activities, services, etc.)
        if (preg_match('/<application[^>]*>(.*?)<\/application>/s', $xml, $match)) {
            preg_match_all('/<(activity|service|receiver|provider)[^>]*>.*?<\/\1>|<(activity|service|receiver|provider)[^>]*\/>/s', $match[1], $appMatches);
            $application = $appMatches[0] ?? [];
        }

        return [
            'permissions' => $permissions,
            'application' => $application,
        ];
    }

    /**
     * Inject permissions into manifest
     */
    protected function injectPermissions(string $manifest, array $permissions): string
    {
        if (empty($permissions)) {
            return $manifest;
        }

        // First, remove any existing plugin permission comments to avoid duplicates
        $manifest = preg_replace('/\s*<!-- NativePHP Plugin Permissions -->\n/s', '', $manifest);

        $permissionBlock = "\n    <!-- NativePHP Plugin Permissions -->\n";
        $hasNewPermissions = false;

        foreach ($permissions as $permission) {
            if (is_string($permission) && ! str_contains($permission, '<')) {
                $permission = "<uses-permission android:name=\"{$permission}\" />";
            }
            if (! str_contains($manifest, $permission)) {
                $permissionBlock .= "    {$permission}\n";
                $hasNewPermissions = true;
            }
        }

        // Only inject if there are new permissions to add
        if (! $hasNewPermissions) {
            return $manifest;
        }

        // Insert before <application
        return preg_replace(
            '/(\s*<application)/s',
            $permissionBlock.'$1',
            $manifest,
            1
        );
    }

    /**
     * Inject uses-feature entries into manifest
     */
    protected function injectFeatures(string $manifest, array $features): string
    {
        if (empty($features)) {
            return $manifest;
        }

        // First, remove any existing plugin feature comments to avoid duplicates
        $manifest = preg_replace('/\s*<!-- NativePHP Plugin Features -->\n/s', '', $manifest);

        $featureBlock = "\n    <!-- NativePHP Plugin Features -->\n";
        $hasNewFeatures = false;

        foreach ($features as $feature) {
            $name = $feature['name'] ?? null;
            if (! $name) {
                continue;
            }

            // Skip if this feature already exists
            if (str_contains($manifest, "android:name=\"{$name}\"")) {
                continue;
            }

            $required = isset($feature['required']) ? ($feature['required'] ? 'true' : 'false') : 'true';
            $featureBlock .= "    <uses-feature android:name=\"{$name}\" android:required=\"{$required}\" />\n";
            $hasNewFeatures = true;
        }

        // Only inject if there are new features to add
        if (! $hasNewFeatures) {
            return $manifest;
        }

        // Insert before <application
        return preg_replace(
            '/(\s*<application)/s',
            $featureBlock.'$1',
            $manifest,
            1
        );
    }

    /**
     * Inject application entries into manifest
     */
    protected function injectApplicationEntries(string $manifest, array $entries): string
    {
        if (empty($entries)) {
            return $manifest;
        }

        // First, remove any existing plugin component sections to avoid duplicates
        // This removes the comment and all following plugin-injected entries up until the next non-plugin content
        $manifest = preg_replace(
            '/\s*<!-- NativePHP Plugin Components -->.*?(?=\s*<\/application>|\s*<!-- (?!NativePHP Plugin))/s',
            '',
            $manifest
        );

        $entryBlock = "\n        <!-- NativePHP Plugin Components -->\n";
        $hasNewEntries = false;

        foreach ($entries as $entry) {
            // Extract android:name from the entry to check for duplicates
            if (preg_match('/android:name="([^"]+)"/', $entry, $matches)) {
                $componentName = $matches[1];
                // Check if this component already exists in the manifest
                if (str_contains($manifest, "android:name=\"{$componentName}\"")) {
                    continue;
                }
            }
            $entryBlock .= "        {$entry}\n";
            $hasNewEntries = true;
        }

        // Only inject if there are new entries to add
        if (! $hasNewEntries) {
            return $manifest;
        }

        // Insert before </application>
        return preg_replace(
            '/(\s*<\/application>)/s',
            $entryBlock.'$1',
            $manifest,
            1
        );
    }

    /**
     * Add Maven repositories from plugins to settings.gradle.kts
     */
    protected function addGradleRepositories(Collection $plugins): void
    {
        $settingsGradlePath = $this->androidProjectPath.'/settings.gradle.kts';

        if (! $this->files->exists($settingsGradlePath)) {
            return;
        }

        $settingsGradle = $this->files->get($settingsGradlePath);

        $repositories = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->getAndroidRepositories() as $repo) {
                $repositories[] = $repo;
            }
        }

        if (empty($repositories)) {
            return;
        }

        // Build repository blocks
        $repoBlocks = [];
        foreach ($repositories as $repo) {
            $url = $repo['url'] ?? null;
            if (! $url) {
                continue;
            }

            // Skip if already exists
            if (str_contains($settingsGradle, $url)) {
                continue;
            }

            $repoBlock = $this->buildRepositoryBlock($repo);
            if ($repoBlock) {
                $repoBlocks[] = $repoBlock;
            }
        }

        if (empty($repoBlocks)) {
            return;
        }

        // Build the injection block
        $injection = "\n        // NativePHP Plugin Repositories\n";
        foreach ($repoBlocks as $block) {
            $injection .= $block;
        }

        // Find the dependencyResolutionManagement.repositories block and inject
        // We need to inject after the opening brace of repositories {}
        $pattern = '/(dependencyResolutionManagement\s*\{[^}]*repositories\s*\{)/s';

        if (preg_match($pattern, $settingsGradle)) {
            $settingsGradle = preg_replace(
                $pattern,
                '$1'.$injection,
                $settingsGradle,
                1
            );

            $this->files->put($settingsGradlePath, $settingsGradle);
        }
    }

    /**
     * Build a Gradle repository block from config
     */
    protected function buildRepositoryBlock(array $repo): ?string
    {
        $url = $repo['url'];
        $credentials = $repo['credentials'] ?? null;

        if ($credentials) {
            $username = $this->substituteEnvPlaceholders($credentials['username'] ?? 'mapbox');
            $password = $this->substituteEnvPlaceholders($credentials['password'] ?? '');

            return <<<KOTLIN
        maven {
            url = uri("{$url}")
            credentials {
                username = "{$username}"
                password = "{$password}"
            }
        }

KOTLIN;
        }

        return <<<KOTLIN
        maven { url = uri("{$url}") }

KOTLIN;
    }

    /**
     * Substitute ${ENV_VAR} placeholders with actual environment values
     */
    protected function substituteEnvPlaceholders(string $value): string
    {
        return preg_replace_callback('/\$\{(\w+)\}/', function ($matches) {
            $envVar = $matches[1];
            $envValue = env($envVar);

            if ($envValue === null) {
                // Return the placeholder as-is if not found - validation will catch this
                return $matches[0];
            }

            return $envValue;
        }, $value);
    }

    /**
     * Add Gradle dependencies from plugins
     */
    protected function addGradleDependencies(Collection $plugins): void
    {
        $buildGradlePath = $this->androidProjectPath.'/app/build.gradle.kts';
        $buildGradle = $this->files->get($buildGradlePath);

        $dependenciesByType = [];

        foreach ($plugins as $plugin) {
            $androidDeps = $plugin->getAndroidDependencies();

            foreach ($androidDeps as $type => $libraries) {
                if (! isset($dependenciesByType[$type])) {
                    $dependenciesByType[$type] = [];
                }
                foreach ($libraries as $library) {
                    $dependenciesByType[$type][] = $library;
                }
            }
        }

        if (empty($dependenciesByType)) {
            return;
        }

        // Find dependencies block and add new ones
        $dependencyBlock = "\n    // NativePHP Plugin Dependencies\n";
        foreach ($dependenciesByType as $type => $libraries) {
            foreach (array_unique($libraries) as $dep) {
                if (! str_contains($buildGradle, $dep)) {
                    // Handle platform() BOMs specially - they need platform() outside the quotes
                    if (preg_match('/^platform\((.+)\)$/', $dep, $matches)) {
                        $dependencyBlock .= "    {$type}(platform(\"{$matches[1]}\"))\n";
                    } else {
                        $dependencyBlock .= "    {$type}(\"{$dep}\")\n";
                    }
                }
            }
        }

        // Insert into dependencies block
        $buildGradle = preg_replace(
            '/(dependencies\s*\{)/s',
            '$1'.$dependencyBlock,
            $buildGradle,
            1
        );

        $this->files->put($buildGradlePath, $buildGradle);
    }

    /**
     * Extract class name from full path
     */
    protected function extractClassName(string $classPath): string
    {
        // com.nativephp.plugin.example.ExampleFunctions.DoSomething
        // -> ExampleFunctions.DoSomething
        $parts = explode('.', $classPath);

        return implode('.', array_slice($parts, -2));
    }

    /**
     * Determine parameter to pass based on requirements
     */
    protected function determineParameter(array $params): string
    {
        if (in_array('activity', $params)) {
            return 'activity';
        }

        if (in_array('context', $params)) {
            return 'context';
        }

        return 'activity';  // Default to activity
    }

    /**
     * Get list of generated files
     */
    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
    }

    /**
     * Clean up generated plugin files
     */
    public function clean(): void
    {
        if ($this->files->isDirectory($this->generatedPath)) {
            $this->files->deleteDirectory($this->generatedPath);
        }
    }
}
