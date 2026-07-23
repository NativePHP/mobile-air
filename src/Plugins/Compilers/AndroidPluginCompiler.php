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

    /**
     * Root of all compiler output — a dedicated Gradle source root the
     * compiler owns outright and deletes at the start of every compile.
     * Registered in app/build.gradle.kts (shipped in the template;
     * injected into older scaffolds by registerPluginSourceRoot()).
     */
    protected string $generatedPath;

    /**
     * Where the registration files go inside the generated root. The path
     * mirrors their Kotlin package so IDE inspections stay quiet.
     */
    protected string $registrationPath;

    /**
     * Where compiler output lived before the dedicated source root:
     * registrations and no-package fallback copies under src/main/java.
     * Removed on every compile.
     */
    protected string $legacyGeneratedPath;

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

        $this->generatedPath = $this->androidProjectPath.'/app/src/nativephp/kotlin';

        // Plugin registration always goes in the core NativePHP package
        $this->registrationPath = $this->generatedPath.'/com/nativephp/mobile/bridge/plugins';

        $this->legacyGeneratedPath = $this->androidProjectPath.'/app/src/main/java/com/nativephp/mobile/bridge/plugins';
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
        // Note: registrations stay in the com.nativephp.mobile.bridge.plugins
        // package regardless of app ID — it's part of the core NativePHP package

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

        // The generated plugin tree is fully derived from the installed
        // plugins, so start from a clean slate. Copying over the previous
        // output would leave stale files behind when a plugin deletes or
        // renames a source file (or is removed entirely), producing
        // duplicate-class build failures in Gradle.
        $this->clean();
        $this->files->ensureDirectoryExists($this->registrationPath);

        // Make sure the generated source root is compiled — the template
        // declares it, this heals scaffolds installed before it existed
        // (runs even when the list is empty: registrations live there).
        $this->registerPluginSourceRoot();

        // Emit ProGuard/R8 keep rules for every plugin (runs even when the
        // list is empty so a removed plugin's stale rules are cleared).
        $this->injectPluginProguardRules($allPlugins);

        // Declare plugin-required Gradle plugins in the root build file (runs
        // even when the list is empty so a removed plugin's declaration is cleared).
        $this->injectGradlePlugins($allPlugins);

        if ($allPlugins->isEmpty()) {
            $this->generateEmptyRegistration();
            $this->generateEmptyRendererRegistration();

            return;
        }

        // Check if there are any plugins with Android bridge functions or init functions
        $hasAndroidFunctions = $allPlugins->filter(function (Plugin $p) {
            foreach ($p->getBridgeFunctions() as $function) {
                if (! empty($function['android'])) {
                    return true;
                }
            }

            return false;
        })->isNotEmpty();

        $hasInitFunctions = $allPlugins->filter(function (Plugin $p) {
            return $p->getAndroidInitFunction() !== null;
        })->isNotEmpty();

        // Copy plugin source files for plugins that have Android code,
        // pruning copies older compiler versions left under src/main/java
        $allPlugins->filter(fn (Plugin $p) => $p->hasAndroidCode())
            ->each(function (Plugin $plugin) {
                $this->removeLegacyPackageCopies($plugin);
                $this->copyPluginSources($plugin);
            });

        // Generate the bridge function registration file
        if ($hasAndroidFunctions || $hasInitFunctions) {
            $this->generateBridgeFunctionRegistration($allPlugins);
        } else {
            $this->generateEmptyRegistration();
        }

        // Generate UI plugin renderer registration
        $this->generateRendererRegistration($allPlugins);

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
     * Write ProGuard/R8 rules for all plugins into the app's
     * proguard-rules.pro, inside a marker-delimited block that is rebuilt
     * on every compile (idempotent; stale rules from removed plugins are
     * dropped). Inert unless minification is enabled.
     *
     * Two sources per plugin:
     *  - generated `-keep` rules for each distinct Kotlin package found in
     *    the plugin's sources — plugin entry points are reached via
     *    generated registrations, build-time patches, and JNI, none of
     *    which R8 can trace, so plugin classes must survive shrinking;
     *  - verbatim rules the plugin declares as `android.proguard_rules`
     *    in nativephp.json (dependency `-dontwarn`s and the like).
     */
    protected function injectPluginProguardRules(Collection $plugins): void
    {
        $proguardPath = $this->androidProjectPath.'/app/proguard-rules.pro';

        if (! $this->files->exists($proguardPath)) {
            return;
        }

        $block = $this->buildPluginProguardBlock($plugins);
        $content = $this->files->get($proguardPath);

        $begin = '# BEGIN nativephp-plugin-rules';
        $end = '# END nativephp-plugin-rules';

        if (str_contains($content, $begin) && str_contains($content, $end)) {
            $content = preg_replace(
                '/'.preg_quote($begin, '/').'.*?'.preg_quote($end, '/').'/s',
                $block,
                $content,
                1
            );
        } else {
            $content = rtrim($content)."\n\n".$block."\n";
        }

        $this->files->put($proguardPath, $content);
    }

    /**
     * Declare Gradle plugins required by installed plugins in the root
     * build.gradle.kts plugins {} block, inside a marker-delimited block
     * rebuilt on every compile (idempotent; a removed plugin's declaration
     * is dropped). Declared from `android.gradle_plugins` in nativephp.json.
     *
     * Entries default to `apply false`: the plugin lands on the build
     * classpath only, and the app module decides whether to apply it (e.g.
     * google-services is applied only when a google-services.json exists).
     */
    protected function injectGradlePlugins(Collection $plugins): void
    {
        $rootGradlePath = $this->androidProjectPath.'/build.gradle.kts';

        if (! $this->files->exists($rootGradlePath)) {
            return;
        }

        $content = $this->files->get($rootGradlePath);

        $begin = '// BEGIN nativephp-plugin-gradle-plugins';
        $end = '// END nativephp-plugin-gradle-plugins';

        $block = $this->buildGradlePluginsBlock($plugins, $content);

        if (str_contains($content, $begin) && str_contains($content, $end)) {
            $content = preg_replace(
                '/[ \t]*'.preg_quote($begin, '/').'.*?'.preg_quote($end, '/').'\n?/s',
                $block,
                $content,
                1
            );
        } elseif ($block !== '') {
            // Insert at the end of the plugins {} block. The root build file's
            // plugins block contains no nested braces, so the first closing
            // brace on its own line terminates it.
            $content = preg_replace(
                '/(plugins\s*\{.*?)(\n\})/s',
                '$1'."\n".rtrim($block).'$2',
                $content,
                1
            );
        } else {
            // Nothing to declare and no stale block to clear
            return;
        }

        $this->files->put($rootGradlePath, $content);
    }

    /**
     * Build the marker-delimited plugins declarations. Pure (no IO on the
     * android project) so it is unit-testable. Returns '' when no plugin
     * declares anything. $existingContent is used to skip ids the build
     * file already declares outside our markers.
     */
    public function buildGradlePluginsBlock(Collection $plugins, string $existingContent = ''): string
    {
        $entries = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->getAndroidGradlePlugins() as $gradlePlugin) {
                $id = $gradlePlugin['id'] ?? null;
                $version = $gradlePlugin['version'] ?? null;

                if (! $id || ! $version) {
                    $this->warn("Plugin '{$plugin->name}' declares a gradle plugin without id/version — skipping");

                    continue;
                }

                // Guard against injecting arbitrary Kotlin into the build script
                if (! preg_match('/^[A-Za-z0-9._-]+$/', $id) || ! preg_match('/^[A-Za-z0-9._+-]+$/', $version)) {
                    $this->warn("Plugin '{$plugin->name}' declares gradle plugin with invalid id/version '{$id}:{$version}' — skipping");

                    continue;
                }

                // First declaration wins across plugins
                if (isset($entries[$id])) {
                    continue;
                }

                // Skip ids already declared outside our marker block
                if ($existingContent !== '' && str_contains($existingContent, "id(\"{$id}\")")) {
                    $withoutBlock = preg_replace(
                        '/\/\/ BEGIN nativephp-plugin-gradle-plugins.*?\/\/ END nativephp-plugin-gradle-plugins/s',
                        '',
                        $existingContent
                    );
                    if (str_contains($withoutBlock, "id(\"{$id}\")")) {
                        continue;
                    }
                }

                $apply = $gradlePlugin['apply'] ?? false;
                $entries[$id] = "    id(\"{$id}\") version \"{$version}\" apply ".($apply ? 'true' : 'false');
            }
        }

        if (empty($entries)) {
            return '';
        }

        return "    // BEGIN nativephp-plugin-gradle-plugins\n"
            ."    // Auto-generated on every build from installed plugins — do not edit.\n"
            .implode("\n", $entries)."\n"
            ."    // END nativephp-plugin-gradle-plugins\n";
    }

    /**
     * Build the marker-delimited rules block. Pure (no IO on the android
     * project) so it is unit-testable.
     */
    public function buildPluginProguardBlock(Collection $plugins): string
    {
        $lines = [
            '# BEGIN nativephp-plugin-rules',
            '# Auto-generated on every build from installed plugins — do not edit.',
        ];

        foreach ($plugins as $plugin) {
            $packages = $this->pluginKotlinPackages($plugin);
            $declared = $plugin->getAndroidProguardRules();

            if (empty($packages) && empty($declared)) {
                continue;
            }

            $lines[] = "# {$plugin->name}";
            foreach ($packages as $package) {
                $lines[] = "-keep class {$package}.** { *; }";
            }
            foreach ($declared as $rule) {
                $lines[] = $rule;
            }
        }

        $lines[] = '# END nativephp-plugin-rules';

        return implode("\n", $lines);
    }

    /**
     * Distinct Kotlin package declarations across a plugin's Android
     * sources, pruned so a parent package subsumes its children.
     *
     * @return list<string>
     */
    protected function pluginKotlinPackages(Plugin $plugin): array
    {
        $sourcePath = $plugin->getAndroidSourcePath();

        if (! $this->files->isDirectory($sourcePath)) {
            return [];
        }

        $packages = [];
        foreach ($this->files->allFiles($sourcePath) as $file) {
            if ($file->getExtension() !== 'kt') {
                continue;
            }
            $package = $this->extractPackageFromContent($this->files->get($file->getPathname()));
            if ($package !== null) {
                $packages[] = $package;
            }
        }

        $packages = array_values(array_unique($packages));
        sort($packages);

        // Drop packages already covered by a parent's `.**` keep.
        return array_values(array_filter($packages, function ($candidate) use ($packages) {
            foreach ($packages as $other) {
                if ($other !== $candidate && str_starts_with($candidate, $other.'.')) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Copy Kotlin source files from plugin to the generated source root
     *
     * Files are placed at directories matching their package declaration,
     * relative to the generated root — kotlinc doesn't care where a file
     * lives, and keeping everything inside one compiler-owned root is what
     * makes the per-compile wipe safe.
     */
    protected function copyPluginSources(Plugin $plugin): void
    {
        $sourcePath = $plugin->getAndroidSourcePath();

        if (! $this->files->isDirectory($sourcePath)) {
            return;
        }

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
                // Fallback: namespace the path by plugin so two plugins'
                // same-named files can't overwrite each other
                $safeNamespace = $this->sanitizeKotlinName($plugin->getNamespace());
                $destination = $this->generatedPath.'/'.$safeNamespace.'/'.$file->getFilename();
            } else {
                // Place file at path matching its package declaration
                $packagePath = str_replace('.', '/', $package);
                $destination = $this->generatedPath.'/'.$packagePath.'/'.$file->getFilename();
            }

            $this->files->ensureDirectoryExists(dirname($destination));
            $this->files->put($destination, $content);
            $this->generatedFiles[] = $destination;
        }
    }

    /**
     * Ensure the generated source root is registered in app/build.gradle.kts.
     *
     * The template declares it, but scaffolds installed before the root
     * existed won't have it — without this, copies in the generated root
     * would never compile. Idempotent: skipped when any mention of the
     * root is already present.
     */
    protected function registerPluginSourceRoot(): void
    {
        $buildGradlePath = $this->androidProjectPath.'/app/build.gradle.kts';

        if (! $this->files->exists($buildGradlePath)) {
            return;
        }

        $content = $this->files->get($buildGradlePath);

        if (str_contains($content, 'src/nativephp/kotlin')) {
            return;
        }

        $block = <<<'KOTLIN'

            // Generated NativePHP plugin sources — owned by the plugin compiler,
            // wiped and rebuilt on every compile. Do not edit.
            sourceSets.getByName("main") {
                java.srcDir("src/nativephp/kotlin")
            }
        KOTLIN;

        $patched = preg_replace('/^(android\s*\{)/m', '$1'.$block, $content, 1, $count);

        if ($count === 1) {
            $this->files->put($buildGradlePath, $patched);
        } else {
            $this->warn('Could not find the android {} block in app/build.gradle.kts — add java.srcDir("src/nativephp/kotlin") to its main source set manually.');
        }
    }

    /**
     * Delete copies that older compiler versions placed at package-derived
     * paths under src/main/java — outside the generated root — so they
     * don't re-declare the classes now copied into the root. Only files
     * matching a currently-installed plugin's sources can be located this
     * way; stale copies of files renamed or removed before the upgrade
     * have to be cleaned up manually (or via native:install).
     */
    protected function removeLegacyPackageCopies(Plugin $plugin): void
    {
        $sourcePath = $plugin->getAndroidSourcePath();

        if (! $this->files->isDirectory($sourcePath)) {
            return;
        }

        $javaBasePath = $this->androidProjectPath.'/app/src/main/java';

        foreach ($this->files->allFiles($sourcePath) as $file) {
            if ($file->getExtension() !== 'kt') {
                continue;
            }

            $package = $this->extractPackageFromContent($this->files->get($file->getPathname()));

            if ($package === null) {
                // No-package fallback copies lived under the legacy
                // generated dir, which clean() removes wholesale
                continue;
            }

            $legacyCopy = $javaBasePath.'/'.str_replace('.', '/', $package).'/'.$file->getFilename();

            if ($this->files->exists($legacyCopy)) {
                $this->files->delete($legacyCopy);
                $this->pruneEmptyDirectories(dirname($legacyCopy), $javaBasePath);
            }
        }
    }

    /**
     * Remove now-empty directories walking up from $dir to (excluding)
     * $stopAt. Stops at the first non-empty directory, so package dirs
     * shared with hand-written app code are left alone.
     */
    protected function pruneEmptyDirectories(string $dir, string $stopAt): void
    {
        while ($dir !== $stopAt && str_starts_with($dir, $stopAt.'/')) {
            if (! $this->files->isDirectory($dir)) {
                break;
            }

            if (count($this->files->files($dir, true)) > 0 || count($this->files->directories($dir)) > 0) {
                break;
            }

            $this->files->deleteDirectory($dir);
            $dir = dirname($dir);
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
        $initFunctions = [];

        foreach ($plugins as $plugin) {
            // Collect bridge function registrations
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

            // Collect init functions
            $initFunction = $plugin->getAndroidInitFunction();
            if ($initFunction) {
                $initFunctions[] = [
                    'function' => $initFunction,
                    'plugin' => $plugin->name,
                ];
            }
        }

        $content = $this->renderRegistrationTemplate($registrations, $initFunctions);
        $path = $this->registrationPath.'/PluginBridgeFunctionRegistration.kt';

        $this->files->put($path, $content);
        $this->generatedFiles[] = $path;
    }

    /**
     * Render the Kotlin registration file
     */
    protected function renderRegistrationTemplate(array $registrations, array $initFunctions = []): string
    {
        // Build imports from the android class paths in nativephp.json
        $imports = collect($registrations)
            ->pluck('class')
            ->map(fn ($class) => $this->extractImportPath($class))
            ->unique()
            ->sort()
            ->map(fn ($package) => "import {$package}")
            ->implode("\n");

        // Add imports for init functions (top-level Kotlin functions need full path import)
        $initImports = collect($initFunctions)
            ->pluck('function')
            ->unique()
            ->sort()
            ->map(fn ($func) => "import {$func}")
            ->implode("\n");

        if ($initImports) {
            $imports = $imports ? $imports."\n".$initImports : $initImports;
        }

        $registerCalls = collect($registrations)
            ->map(function ($reg) {
                $className = $this->extractClassName($reg['class']);
                $params = $reg['params'] ?? ['activity'];
                $paramString = $this->determineParameter($params);

                return "    // Plugin: {$reg['plugin']}\n    registry.register(\"{$reg['name']}\", {$className}({$paramString}))";
            })
            ->implode("\n\n");

        // Generate context-only registrations for cold-boot WorkManager execution
        $contextRegisterCalls = collect($registrations)
            ->filter(function ($reg) {
                $params = $reg['params'] ?? ['activity'];

                return ! in_array('activity', $params) && in_array('context', $params);
            })
            ->map(function ($reg) {
                $className = $this->extractClassName($reg['class']);

                return "    // Plugin: {$reg['plugin']}\n    registry.register(\"{$reg['name']}\", {$className}(context))";
            })
            ->implode("\n\n");

        $initCalls = collect($initFunctions)
            ->map(function ($init) {
                // Extract just the function name from the full path
                $parts = explode('.', $init['function']);
                $funcName = end($parts);

                return "    // Plugin: {$init['plugin']}\n    {$funcName}(context)";
            })
            ->implode("\n\n");

        return Stub::make('android/PluginBridgeFunctionRegistration.kt.stub')
            ->replaceAll([
                'IMPORTS' => $imports,
                'INIT_FUNCTIONS' => $initCalls,
                'REGISTRATIONS' => $registerCalls,
                'CONTEXT_REGISTRATIONS' => $contextRegisterCalls ?: '    // No context-only bridge functions registered',
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
        $this->files->ensureDirectoryExists($this->registrationPath);

        $content = Stub::make('android/PluginBridgeFunctionRegistration.empty.kt.stub')->render();

        $path = $this->registrationPath.'/PluginBridgeFunctionRegistration.kt';
        $this->files->put($path, $content);
        $this->generatedFiles[] = $path;
    }

    /**
     * Generate PluginRendererRegistration.kt for UI plugin renderers
     */
    protected function generateRendererRegistration(Collection $plugins): void
    {
        $registrations = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->getComponents() as $component) {
                if (empty($component['android_renderer'])) {
                    continue;
                }

                $registrations[] = [
                    'type' => $component['type'],
                    'renderer' => $component['android_renderer'],
                    'plugin' => $plugin->name,
                ];
            }
        }

        if (empty($registrations)) {
            $this->generateEmptyRendererRegistration();

            return;
        }

        $imports = collect($registrations)
            ->pluck('renderer')
            ->unique()
            ->sort()
            ->map(fn ($renderer) => "import {$renderer}")
            ->implode("\n");

        $registerCalls = collect($registrations)
            ->map(function ($reg) {
                // Extract just the class name from FQN: com.vendor.ui.RendererName → RendererName
                $parts = explode('.', $reg['renderer']);
                $className = end($parts);

                return "    // Plugin: {$reg['plugin']}\n    NativeRendererRegistry.register(\"{$reg['type']}\", NodeRenderer { node, modifier ->\n        {$className}.Render(node, modifier)\n    })";
            })
            ->implode("\n\n");

        $content = Stub::make('android/PluginRendererRegistration.kt.stub')
            ->replaceAll([
                'IMPORTS' => $imports,
                'REGISTRATIONS' => $registerCalls,
            ])
            ->render();

        $path = $this->registrationPath.'/PluginRendererRegistration.kt';
        $this->files->put($path, $content);
        $this->generatedFiles[] = $path;
    }

    /**
     * Generate empty renderer registration when no UI plugins
     */
    protected function generateEmptyRendererRegistration(): void
    {
        $this->files->ensureDirectoryExists($this->registrationPath);

        $content = Stub::make('android/PluginRendererRegistration.empty.kt.stub')->render();

        $path = $this->registrationPath.'/PluginRendererRegistration.kt';
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
        if (! empty($intentFilters)) {
            $nestedContent .= $this->buildIntentFilters($intentFilters);
        }

        // Add meta-data support at service level
        $metaData = $service['meta_data'] ?? $service['meta-data'] ?? [];
        if (! empty($metaData)) {
            $nestedContent .= $this->buildComponentMetaData($metaData);
        }

        if (! empty($nestedContent)) {
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

        $nestedContent = '';

        // Support both snake_case and kebab-case
        $intentFilters = $receiver['intent_filters'] ?? $receiver['intent-filters'] ?? [];
        if (! empty($intentFilters)) {
            $nestedContent .= $this->buildIntentFilters($intentFilters);
        }

        // Add meta-data support at receiver level (e.g. for AppWidgetProvider)
        $metaData = $receiver['meta_data'] ?? $receiver['meta-data'] ?? [];
        if (! empty($metaData)) {
            $nestedContent .= $this->buildComponentMetaData($metaData);
        }

        if (! empty($nestedContent)) {
            return "<receiver\n            {$attrString}>\n{$nestedContent}        </receiver>";
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
        $authentication = $repo['authentication'] ?? null;

        if ($credentials) {
            $username = $this->substituteEnvPlaceholders($credentials['username'] ?? 'mapbox');
            $password = $this->substituteEnvPlaceholders($credentials['password'] ?? '');

            $authBlock = '';
            if ($authentication === 'basic') {
                $authBlock = <<<'KOTLIN'

                authentication {
                    create<BasicAuthentication>("basic")
                }
KOTLIN;
            }

            return <<<KOTLIN
        maven {
            url = uri("{$url}"){$authBlock}
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

        // Where registrations and fallback copies lived before the
        // dedicated source root — always compiler-owned, safe to drop
        if ($this->files->isDirectory($this->legacyGeneratedPath)) {
            $this->files->deleteDirectory($this->legacyGeneratedPath);
        }
    }
}
