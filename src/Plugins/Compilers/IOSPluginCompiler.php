<?php

namespace Native\Mobile\Plugins\Compilers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Native\Mobile\Exceptions\PluginConflictException;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginHookRunner;
use Native\Mobile\Plugins\PluginRegistry;
use Native\Mobile\Support\Stub;

class IOSPluginCompiler
{
    protected string $iosProjectPath;

    protected string $generatedPath;

    protected ?PluginHookRunner $hookRunner = null;

    protected $output = null;

    protected string $appId = '';

    protected array $config = [];

    public function __construct(
        protected Filesystem $files,
        protected PluginRegistry $registry,
        protected string $basePath
    ) {
        $this->iosProjectPath = $basePath.'/ios';
        $this->generatedPath = $this->iosProjectPath.'/NativePHP/Bridge/Plugins';
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
     * Set the app ID for hooks context
     */
    public function setAppId(string $appId): self
    {
        $this->appId = $appId;

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
                platform: 'ios',
                buildPath: $this->iosProjectPath,
                appId: $this->appId,
                config: $this->config,
                plugins: $this->registry->all(),
                output: $this->output
            );
        }

        return $this->hookRunner;
    }

    /**
     * Compile all plugins for iOS
     */
    public function compile(): void
    {
        // Check for plugin conflicts before compiling
        $conflicts = $this->registry->detectConflicts();
        if (! empty($conflicts)) {
            throw new PluginConflictException($conflicts);
        }

        $allPlugins = $this->registry->all();
        $hookRunner = $this->getHookRunner();

        // Run pre-compile hooks
        $hookRunner->runPreCompileHooks();

        // Get plugins with iOS code (for copying files)
        $pluginsWithCode = $allPlugins->filter(fn (Plugin $p) => $p->hasIosCode());

        // Get plugins with iOS bridge functions (for registration)
        $pluginsWithFunctions = $allPlugins->filter(function (Plugin $p) {
            $functions = $p->getBridgeFunctions();
            foreach ($functions as $function) {
                if (! empty($function['ios'])) {
                    return true;
                }
            }

            return false;
        });

        // Get plugins with iOS info_plist entries or dependencies
        $pluginsWithIosData = $allPlugins->filter(function (Plugin $p) {
            return ! empty($p->getIosInfoPlist()) || ! empty($p->getIosDependencies());
        });

        // If no plugins have any iOS-related data, generate empty registration
        if ($pluginsWithCode->isEmpty() && $pluginsWithFunctions->isEmpty() && $pluginsWithIosData->isEmpty()) {
            $this->generateEmptyRegistration();

            return;
        }

        // Ensure generated directory exists
        $this->files->ensureDirectoryExists($this->generatedPath);

        // Copy plugin source files
        $pluginsWithCode->each(fn (Plugin $plugin) => $this->copyPluginSources($plugin));

        // Generate the registration file (uses all plugins, filters for iOS functions internally)
        $this->generateBridgeFunctionRegistration($allPlugins);

        // Merge Info.plist entries (for any plugins with iOS permissions)
        $this->mergeInfoPlistEntries($allPlugins);

        // Merge background modes into Info.plist
        $this->mergeBackgroundModes($allPlugins);

        // Merge entitlements from plugins
        $this->mergeEntitlements($allPlugins);

        // Add Swift Package dependencies
        $this->addSwiftPackageDependencies($allPlugins);

        // Add CocoaPods dependencies
        $this->addPodDependencies($allPlugins);

        // Update Xcode project file
        $this->updateXcodeProject($allPlugins);

        // Copy manifest-declared assets
        $hookRunner->copyManifestAssets();

        // Run copy-assets hooks
        $hookRunner->runCopyAssetsHooks();

        // Run post-compile hooks
        $hookRunner->runPostCompileHooks();
    }

    /**
     * Copy Swift source files from plugin to iOS project
     */
    protected function copyPluginSources(Plugin $plugin): void
    {
        $sourcePath = $plugin->getIosSourcePath();

        if (! $this->files->isDirectory($sourcePath)) {
            return;
        }

        // Create plugin-specific subdirectory
        $pluginDir = $this->generatedPath.'/'.$plugin->getNamespace();
        $this->files->ensureDirectoryExists($pluginDir);

        // Copy all Swift files recursively
        $this->copySwiftFilesRecursively($sourcePath, $pluginDir);
    }

    /**
     * Recursively copy Swift files, preserving directory structure
     */
    protected function copySwiftFilesRecursively(string $source, string $destination): void
    {
        // First, copy any Swift files at the root level
        $rootFiles = glob($source.'/*.swift') ?: [];
        foreach ($rootFiles as $file) {
            $filename = basename($file);
            $this->files->copy($file, $destination.'/'.$filename);
        }

        // Then recursively handle subdirectories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destPath = $destination.'/'.$relativePath;

            if ($item->isDir()) {
                $this->files->ensureDirectoryExists($destPath);
            } elseif ($item->isFile() && $item->getExtension() === 'swift') {
                // Skip root files (already copied above)
                if (dirname($item->getPathname()) !== $source) {
                    $this->files->copy($item->getPathname(), $destPath);
                }
            }
        }
    }

    /**
     * Generate PluginBridgeFunctionRegistration.swift
     */
    protected function generateBridgeFunctionRegistration(Collection $plugins): void
    {
        $registrations = [];
        $initFunctions = [];

        foreach ($plugins as $plugin) {
            // Collect bridge function registrations
            foreach ($plugin->getBridgeFunctions() as $function) {
                if (empty($function['ios'])) {
                    continue;
                }

                $registrations[] = [
                    'name' => $function['name'],
                    'class' => $function['ios'],
                    'plugin' => $plugin->name,
                ];
            }

            // Collect init functions
            $initFunction = $plugin->getIosInitFunction();
            if ($initFunction) {
                $initFunctions[] = [
                    'function' => $initFunction,
                    'plugin' => $plugin->name,
                ];
            }
        }

        $content = $this->renderRegistrationTemplate($registrations, $initFunctions);
        $path = $this->generatedPath.'/PluginBridgeFunctionRegistration.swift';

        $this->files->put($path, $content);
    }

    /**
     * Render the Swift registration file
     */
    protected function renderRegistrationTemplate(array $registrations, array $initFunctions = []): string
    {
        $registerCalls = collect($registrations)
            ->map(function ($reg) {
                return "    // Plugin: {$reg['plugin']}\n    registry.register(\"{$reg['name']}\", function: {$reg['class']}())";
            })
            ->implode("\n\n");

        $initCalls = collect($initFunctions)
            ->map(function ($init) {
                return "    // Plugin: {$init['plugin']}\n    {$init['function']}()";
            })
            ->implode("\n\n");

        return Stub::make('ios/PluginBridgeFunctionRegistration.swift.stub')
            ->replace('REGISTRATIONS', $registerCalls)
            ->replace('INIT_FUNCTIONS', $initCalls)
            ->render();
    }

    /**
     * Generate empty registration when no plugins
     */
    protected function generateEmptyRegistration(): void
    {
        $this->files->ensureDirectoryExists($this->generatedPath);

        $content = Stub::make('ios/PluginBridgeFunctionRegistration.empty.swift.stub')->render();

        $this->files->put($this->generatedPath.'/PluginBridgeFunctionRegistration.swift', $content);
    }

    /**
     * Merge plugin Info.plist entries into main plist and simulator plist
     */
    protected function mergeInfoPlistEntries(Collection $plugins): void
    {
        // Both device and simulator Info.plist files need plugin entries
        $plistPaths = [
            $this->iosProjectPath.'/NativePHP/Info.plist',
            $this->iosProjectPath.'/NativePHP-simulator-Info.plist',
        ];

        foreach ($plistPaths as $plistPath) {
            if (! $this->files->exists($plistPath)) {
                continue;
            }

            $plist = $this->files->get($plistPath);

            foreach ($plugins as $plugin) {
                // First check for Info.plist file
                $pluginPlistPath = $plugin->path.'/resources/ios/Info.plist';

                if ($this->files->exists($pluginPlistPath)) {
                    $pluginPlist = $this->files->get($pluginPlistPath);
                    $plist = $this->mergePlists($plist, $pluginPlist);
                }

                // Also merge info_plist entries from nativephp.json
                $infoPlistEntries = $plugin->getIosInfoPlist();
                if (! empty($infoPlistEntries)) {
                    $plist = $this->injectPlistEntries($plist, $infoPlistEntries);
                }
            }

            $this->files->put($plistPath, $plist);
        }
    }

    /**
     * Merge two plist files
     */
    protected function mergePlists(string $main, string $plugin): string
    {
        // Extract key-value pairs from plugin plist
        preg_match_all('/<key>([^<]+)<\/key>\s*<string>([^<]+)<\/string>/s', $plugin, $matches, PREG_SET_ORDER);

        $entries = [];
        foreach ($matches as $match) {
            $entries[$match[1]] = $match[2];
        }

        return $this->injectPlistEntries($main, $entries);
    }

    /**
     * Inject entries into plist
     */
    protected function injectPlistEntries(string $plist, array $entries): string
    {
        foreach ($entries as $key => $value) {
            // Check if key already exists
            if (str_contains($plist, "<key>{$key}</key>")) {
                // For array values, merge with existing array
                if (is_array($value)) {
                    $plist = $this->mergeArrayEntry($plist, $key, $value);
                }

                // Skip non-array values that already exist
                continue;
            }

            // Handle plist types
            $entry = $this->handlePlistTypes($key, $value);

            // Add before closing </dict>
            $plist = preg_replace(
                '/(\s*<\/dict>\s*<\/plist>)/s',
                $entry.'$1',
                $plist,
                1
            );
        }

        return $plist;
    }

    protected function handlePlistTypes($key, $value)
    {
        $entry = "";

        if(is_string($key)){
            $entry = "\n\t<key>{$key}</key>";
        }

        // Handle array values
        if (is_array($value)) {
            $firstKey = array_key_first($value);
            if(!is_int($firstKey)){
                $dictContent = '';
                foreach($value as $dictKey => $dictItem) {
                    $dictContent .= $this->handlePlistTypes($dictKey, $dictItem);
                }
                $entry .= "\n\t<dict>{$dictContent}\n\t</dict>";
            } else {
                $arrayContent = '';
                foreach ($value as $arrKey => $arrItem) {
                    $arrayContent .= $this->handlePlistTypes($arrKey, $arrItem);
                }
                $entry .= "\n\t<array>{$arrayContent}\n\t</array>";
            }
        } else if (is_bool($value)) { 
            $entry .= $value ? "<true />\n\t" : "<false />";
        } else {
            // Handle string values - substitute placeholders
            $value = $this->substituteEnvPlaceholders($value);
            $entry .= "\n\t<string>{$value}</string>";
        }

        return $entry;
    }

    /**
     * Merge array values into an existing plist array entry
     */
    protected function mergeArrayEntry(string $plist, string $key, array $values): string
    {
        $pattern = '/(<key>'.preg_quote($key, '/').'<\/key>\s*<array>)(.*?)(<\/array>)/s';

        return preg_replace_callback($pattern, function ($matches) use ($values) {
            $existingContent = $matches[2];
            $newItems = '';

            foreach ($values as $item) {
                $item = $this->substituteEnvPlaceholders($item);
                // Only add if not already present
                if (! str_contains($existingContent, "<string>{$item}</string>")) {
                    $newItems .= "\n\t\t<string>{$item}</string>";
                }
            }

            return $matches[1].$existingContent.$newItems.$matches[3];
        }, $plist);
    }

    /**
     * Substitute ${ENV_VAR} placeholders with actual environment values
     */
    protected function substituteEnvPlaceholders(string $value): string
    {
        return preg_replace_callback('/\$\{([A-Z_][A-Z0-9_]*)\}/', function ($matches) {
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
     * Merge background modes from plugins into Info.plist UIBackgroundModes array
     */
    protected function mergeBackgroundModes(Collection $plugins): void
    {
        $backgroundModes = [];

        foreach ($plugins as $plugin) {
            $modes = $plugin->getIosBackgroundModes();
            foreach ($modes as $mode) {
                $backgroundModes[$mode] = true;
            }
        }

        if (empty($backgroundModes)) {
            return;
        }

        // Both device and simulator Info.plist files need background modes
        $plistPaths = [
            $this->iosProjectPath.'/NativePHP/Info.plist',
            $this->iosProjectPath.'/NativePHP-simulator-Info.plist',
        ];

        foreach ($plistPaths as $plistPath) {
            if (! $this->files->exists($plistPath)) {
                continue;
            }

            $plist = $this->files->get($plistPath);

            // Check if UIBackgroundModes already exists
            if (str_contains($plist, '<key>UIBackgroundModes</key>')) {
                // Merge with existing array
                $plist = $this->mergeArrayEntry($plist, 'UIBackgroundModes', array_keys($backgroundModes));
            } else {
                // Add new UIBackgroundModes array
                $plist = $this->injectPlistEntries($plist, [
                    'UIBackgroundModes' => array_keys($backgroundModes),
                ]);
            }

            $this->files->put($plistPath, $plist);
        }
    }

    /**
     * Merge entitlements from plugins into the app's entitlements file
     */
    protected function mergeEntitlements(Collection $plugins): void
    {
        $entitlementsPath = $this->iosProjectPath.'/NativePHP/NativePHP.entitlements';

        // Collect all entitlements from plugins
        $allEntitlements = [];

        foreach ($plugins as $plugin) {
            $entitlements = $plugin->getIosEntitlements();
            foreach ($entitlements as $key => $value) {
                $allEntitlements[$key] = $value;
            }
        }

        if (empty($allEntitlements)) {
            return;
        }

        // If entitlements file doesn't exist, create it
        if (! $this->files->exists($entitlementsPath)) {
            $this->createEntitlementsFile($entitlementsPath, $allEntitlements);

            return;
        }

        // Merge into existing entitlements file
        $entitlementsPlist = $this->files->get($entitlementsPath);
        $entitlementsPlist = $this->injectEntitlements($entitlementsPlist, $allEntitlements);

        $this->files->put($entitlementsPath, $entitlementsPlist);
    }

    /**
     * Create a new entitlements plist file
     */
    protected function createEntitlementsFile(string $path, array $entitlements): void
    {
        $entries = '';

        foreach ($entitlements as $key => $value) {
            if (is_bool($value)) {
                $entries .= "\t<key>{$key}</key>\n\t<".($value ? 'true' : 'false')."/>\n";
            } elseif (is_array($value)) {
                $arrayContent = '';
                foreach ($value as $item) {
                    $arrayContent .= "\t\t<string>{$item}</string>\n";
                }
                $entries .= "\t<key>{$key}</key>\n\t<array>\n{$arrayContent}\t</array>\n";
            } else {
                $entries .= "\t<key>{$key}</key>\n\t<string>{$value}</string>\n";
            }
        }

        $content = <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
{$entries}</dict>
</plist>
PLIST;

        $this->files->put($path, $content);
    }

    /**
     * Inject entitlements into an existing entitlements plist
     */
    protected function injectEntitlements(string $plist, array $entitlements): string
    {
        foreach ($entitlements as $key => $value) {
            // Skip if key already exists
            if (str_contains($plist, "<key>{$key}</key>")) {
                continue;
            }

            if (is_bool($value)) {
                $entry = "\n\t<key>{$key}</key>\n\t<".($value ? 'true' : 'false').'/>';
            } elseif (is_array($value)) {
                $arrayContent = '';
                foreach ($value as $item) {
                    $arrayContent .= "\n\t\t<string>{$item}</string>";
                }
                $entry = "\n\t<key>{$key}</key>\n\t<array>{$arrayContent}\n\t</array>";
            } else {
                $entry = "\n\t<key>{$key}</key>\n\t<string>{$value}</string>";
            }

            // Add before closing </dict>
            $plist = preg_replace(
                '/(\s*<\/dict>\s*<\/plist>)/s',
                $entry.'$1',
                $plist,
                1
            );
        }

        return $plist;
    }

    /**
     * Add Swift Package Manager dependencies from plugins to project.pbxproj
     */
    protected function addSwiftPackageDependencies(Collection $plugins): void
    {
        $projectPath = $this->iosProjectPath.'/NativePHP.xcodeproj/project.pbxproj';

        if (! $this->files->exists($projectPath)) {
            return;
        }

        $packagesToAdd = [];

        foreach ($plugins as $plugin) {
            $iosDeps = $plugin->getIosDependencies();
            $packages = $iosDeps['swift_packages'] ?? [];

            foreach ($packages as $package) {
                if (isset($package['url'])) {
                    $packagesToAdd[] = $package;
                }
            }
        }

        if (empty($packagesToAdd)) {
            return;
        }

        $pbxproj = $this->files->get($projectPath);

        foreach ($packagesToAdd as $package) {
            $pbxproj = $this->injectSwiftPackage($pbxproj, $package);
        }

        $this->files->put($projectPath, $pbxproj);
    }

    /**
     * Inject a Swift Package into the pbxproj file
     */
    protected function injectSwiftPackage(string $pbxproj, array $package): string
    {
        $url = $package['url'];
        $version = $package['version'] ?? '1.0.0';
        $products = $package['products'] ?? [basename(parse_url($url, PHP_URL_PATH) ?? $url)];
        $packageName = basename(parse_url($url, PHP_URL_PATH) ?? $url);

        // Check if package reference already exists - if so, get its ID
        $packageRefId = $this->findExistingPackageRefId($pbxproj, $url);

        if ($packageRefId === null) {
            // Generate new UUID for the package reference
            $packageRefId = $this->generatePbxUuid();

            // 1. Add XCRemoteSwiftPackageReference section entry
            $pbxproj = $this->addRemotePackageReference($pbxproj, $packageRefId, $packageName, $url, $version);

            // 2. Add to project's packageReferences array
            $pbxproj = $this->addToProjectPackageReferences($pbxproj, $packageRefId, $packageName);
        }

        // 3. For each product, add dependencies to targets (always check all targets)
        foreach ($products as $productName) {
            $pbxproj = $this->addProductDependencyToTargets($pbxproj, $packageRefId, $productName);
        }

        return $pbxproj;
    }

    /**
     * Find existing package reference ID by URL
     */
    protected function findExistingPackageRefId(string $pbxproj, string $url): ?string
    {
        // Look for: ABC123 /* XCRemoteSwiftPackageReference ... */ = { ... repositoryURL = "url"; ... }
        if (preg_match('/([A-F0-9]{24})\s*\/\*\s*XCRemoteSwiftPackageReference[^*]*\*\/\s*=\s*\{[^}]*repositoryURL\s*=\s*"'.preg_quote($url, '/').'"/', $pbxproj, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Generate a unique 24-character hex UUID for pbxproj entries
     */
    protected function generatePbxUuid(): string
    {
        return strtoupper(bin2hex(random_bytes(12)));
    }

    /**
     * Add XCRemoteSwiftPackageReference entry to the pbxproj
     */
    protected function addRemotePackageReference(string $pbxproj, string $id, string $name, string $url, string $version): string
    {
        $entry = <<<ENTRY
		{$id} /* XCRemoteSwiftPackageReference "{$name}" */ = {
			isa = XCRemoteSwiftPackageReference;
			repositoryURL = "{$url}";
			requirement = {
				kind = upToNextMajorVersion;
				minimumVersion = {$version};
			};
		};
ENTRY;

        // Find the /* End XCRemoteSwiftPackageReference section */ marker or create the section
        if (str_contains($pbxproj, '/* End XCRemoteSwiftPackageReference section */')) {
            $pbxproj = str_replace(
                '/* End XCRemoteSwiftPackageReference section */',
                $entry."\n/* End XCRemoteSwiftPackageReference section */",
                $pbxproj
            );
        } else {
            // Create the section before /* End PBXProject section */
            $section = <<<SECTION

/* Begin XCRemoteSwiftPackageReference section */
{$entry}
/* End XCRemoteSwiftPackageReference section */
SECTION;
            $pbxproj = str_replace(
                '/* End PBXProject section */',
                '/* End PBXProject section */'.$section,
                $pbxproj
            );
        }

        return $pbxproj;
    }

    /**
     * Add package reference to the project object's packageReferences array
     */
    protected function addToProjectPackageReferences(string $pbxproj, string $packageRefId, string $packageName): string
    {
        $reference = "\n\t\t\t\t{$packageRefId} /* XCRemoteSwiftPackageReference \"{$packageName}\" */,";

        // Check if packageReferences already exists in the project object
        if (preg_match('/(\s*packageReferences\s*=\s*\()/', $pbxproj)) {
            // Add to existing packageReferences array
            $pbxproj = preg_replace(
                '/(\s*packageReferences\s*=\s*\()/',
                '$1'.$reference,
                $pbxproj,
                1
            );
        } else {
            // Add packageReferences array to the project object (after buildConfigurationList line)
            $packageReferencesArray = "\n\t\t\tpackageReferences = ({$reference}\n\t\t\t);";
            $pbxproj = preg_replace(
                '/(buildConfigurationList\s*=\s*[A-F0-9]+\s*\/\*[^*]*\*\/\s*;)/',
                '$1'.$packageReferencesArray,
                $pbxproj,
                1
            );
        }

        return $pbxproj;
    }

    /**
     * Add XCSwiftPackageProductDependency for each target
     */
    protected function addProductDependencyToTargets(string $pbxproj, string $packageRefId, string $productName): string
    {
        // Find all targets (NativePHP, NativePHP-simulator)
        $targets = ['NativePHP', 'NativePHP-simulator'];

        foreach ($targets as $targetName) {
            // Generate a unique UUID for this product dependency
            $productDepId = $this->generatePbxUuid();

            // 1. Add XCSwiftPackageProductDependency entry
            $pbxproj = $this->addSwiftPackageProductDependency($pbxproj, $productDepId, $packageRefId, $productName);

            // 2. Add to target's packageProductDependencies array
            $pbxproj = $this->addToTargetPackageProductDependencies($pbxproj, $targetName, $productDepId, $productName);
        }

        return $pbxproj;
    }

    /**
     * Add XCSwiftPackageProductDependency section entry
     */
    protected function addSwiftPackageProductDependency(string $pbxproj, string $id, string $packageRefId, string $productName): string
    {
        $entry = <<<ENTRY
		{$id} /* {$productName} */ = {
			isa = XCSwiftPackageProductDependency;
			package = {$packageRefId} /* XCRemoteSwiftPackageReference */;
			productName = {$productName};
		};
ENTRY;

        // Find the /* End XCSwiftPackageProductDependency section */ marker or create the section
        if (str_contains($pbxproj, '/* End XCSwiftPackageProductDependency section */')) {
            $pbxproj = str_replace(
                '/* End XCSwiftPackageProductDependency section */',
                $entry."\n/* End XCSwiftPackageProductDependency section */",
                $pbxproj
            );
        } else {
            // Create the section before the end of the file (before closing brace)
            $section = <<<SECTION

/* Begin XCSwiftPackageProductDependency section */
{$entry}
/* End XCSwiftPackageProductDependency section */
SECTION;
            // Insert before the final closing brace
            $pbxproj = preg_replace('/(\n}\s*)$/', $section.'$1', $pbxproj);
        }

        return $pbxproj;
    }

    /**
     * Add product dependency to a target's packageProductDependencies array
     */
    protected function addToTargetPackageProductDependencies(string $pbxproj, string $targetName, string $productDepId, string $productName): string
    {
        // First, check if this product is already in this target's dependencies
        // Look for the target block and check its packageProductDependencies
        if ($this->targetHasProductDependency($pbxproj, $targetName, $productName)) {
            return $pbxproj;
        }

        $reference = "\n\t\t\t\t{$productDepId} /* {$productName} */,";

        // Find the native target section by looking for its name followed by packageProductDependencies
        // Use a more specific pattern that matches the PBXNativeTarget structure
        // The pattern looks for: name = "TargetName"; (with potential quotes) followed by packageProductDependencies
        $escapedName = preg_quote($targetName, '/');
        $targetPattern = '/(name\s*=\s*"?'.$escapedName.'"?;\s*)([\s\S]*?)(packageProductDependencies\s*=\s*\()/';

        if (preg_match($targetPattern, $pbxproj, $matches)) {
            // Target already has packageProductDependencies, add to it
            $pbxproj = preg_replace(
                $targetPattern,
                '$1$2$3'.$reference,
                $pbxproj,
                1
            );
        } else {
            // Need to add packageProductDependencies array to the target
            // Find the target by name and add after productReference line
            $addArrayPattern = '/(name\s*=\s*"?'.$escapedName.'"?;\s*[\s\S]*?)(productReference\s*=\s*[A-F0-9]+\s*\/\*[^*]*\*\/\s*;)/';

            if (preg_match($addArrayPattern, $pbxproj)) {
                $packageProductDeps = "\n\t\t\tpackageProductDependencies = ({$reference}\n\t\t\t);";
                $pbxproj = preg_replace(
                    $addArrayPattern,
                    '$1$2'.$packageProductDeps,
                    $pbxproj,
                    1
                );
            }
        }

        return $pbxproj;
    }

    /**
     * Check if a target already has a specific product dependency
     */
    protected function targetHasProductDependency(string $pbxproj, string $targetName, string $productName): bool
    {
        // Extract just this target's packageProductDependencies array
        $targetDeps = $this->extractTargetPackageProductDependencies($pbxproj, $targetName);

        if ($targetDeps === null) {
            return false;
        }

        // Check if the product name is in the dependencies
        return str_contains($targetDeps, "/* {$productName} */");
    }

    /**
     * Extract the packageProductDependencies array content for a specific target
     */
    protected function extractTargetPackageProductDependencies(string $pbxproj, string $targetName): ?string
    {
        // Find the target block by looking for its unique structure
        // PBXNativeTarget entries have: name = TargetName; and end with productType = "...";
        $escapedName = preg_quote($targetName, '/');

        // Match from target name to productType (which marks the end of the target block)
        $pattern = '/name\s*=\s*"?'.$escapedName.'"?;.*?productType\s*=\s*"[^"]+"\s*;/s';

        if (! preg_match($pattern, $pbxproj, $matches)) {
            return null;
        }

        $targetBlock = $matches[0];

        // Now extract packageProductDependencies from within this target block
        if (preg_match('/packageProductDependencies\s*=\s*\(([^)]*)\)/', $targetBlock, $depMatches)) {
            return $depMatches[1];
        }

        return null;
    }

    /**
     * Add CocoaPods dependencies from plugins to Podfile
     *
     * Supports pod format:
     *   {"name": "MapboxMaps", "version": "~> 11.0"}
     */
    protected function addPodDependencies(Collection $plugins): void
    {
        $podsToAdd = [];

        foreach ($plugins as $plugin) {
            $iosDeps = $plugin->getIosDependencies();
            $pods = $iosDeps['pods'] ?? [];

            foreach ($pods as $pod) {
                // Handle object format: {"name": "...", "version": "..."}
                if (is_array($pod) && isset($pod['name'])) {
                    $key = $pod['name'];
                    $podsToAdd[$key] = $pod;
                } elseif (is_string($pod)) {
                    // Legacy string format
                    $podsToAdd[$pod] = ['name' => $pod];
                }
            }
        }

        if (empty($podsToAdd)) {
            return;
        }

        // Check if Podfile exists, create if not
        $podfilePath = $this->iosProjectPath.'/Podfile';

        if (! $this->files->exists($podfilePath)) {
            $this->createPodfile($podfilePath);
        }

        $podfile = $this->files->get($podfilePath);

        // Filter out pods that already exist in the Podfile (outside of our managed section)
        $newPods = collect($podsToAdd)
            ->filter(function ($pod) use ($podfile) {
                $name = $pod['name'];
                // Check if pod exists outside of our plugin dependencies section
                $podfileWithoutSection = preg_replace(
                    '/# NativePHP Plugin Dependencies\n(?:[ \t]*pod\s+\'[^\']+\'(?:,\s*\'[^\']+\')?\n?)*/s',
                    '',
                    $podfile
                );

                return ! preg_match("/pod\s+['\"]".preg_quote($name, '/')."['\"]/", $podfileWithoutSection);
            });

        // Build the new plugin pods section
        $newPodLines = $newPods
            ->map(function ($pod) {
                $name = $pod['name'];
                $version = $pod['version'] ?? null;

                if ($version) {
                    return "  pod '{$name}', '{$version}'";
                }

                return "  pod '{$name}'";
            })
            ->implode("\n");

        // Remove existing plugin dependencies section (if any)
        $podfile = preg_replace(
            '/[ \t]*# NativePHP Plugin Dependencies\n(?:[ \t]*pod\s+\'[^\']+\'(?:,\s*\'[^\']+\')?\n?)*/s',
            '',
            $podfile
        );

        // If no new pods to add, just save cleaned podfile
        if ($newPods->isEmpty()) {
            $this->files->put($podfilePath, $podfile);

            return;
        }

        // Check if marker exists (preferred injection point)
        if (str_contains($podfile, '# NATIVEPHP_PLUGIN_PODS')) {
            $podfile = str_replace(
                '# NATIVEPHP_PLUGIN_PODS',
                "# NativePHP Plugin Dependencies\n{$newPodLines}",
                $podfile
            );
        } else {
            // Find the NativePHP target block and insert before its 'end'
            // Use a more precise pattern that only matches within the target block
            $lines = explode("\n", $podfile);
            $result = [];
            $inNativePHPTarget = false;
            $inserted = false;

            foreach ($lines as $line) {
                // Detect start of NativePHP target (not simulator)
                if (preg_match('/^target\s+[\'"]NativePHP[\'"]\s+do/', $line)) {
                    $inNativePHPTarget = true;
                }

                // If we're in the target and hit 'end', insert pods before it
                if ($inNativePHPTarget && ! $inserted && preg_match('/^end\s*$/', $line)) {
                    $result[] = "  # NativePHP Plugin Dependencies";
                    $result[] = $newPodLines;
                    $inserted = true;
                    $inNativePHPTarget = false;
                }

                $result[] = $line;
            }

            $podfile = implode("\n", $result);
        }

        $this->files->put($podfilePath, $podfile);
    }

    /**
     * Create a basic Podfile for the iOS project
     */
    protected function createPodfile(string $podfilePath): void
    {
        $podfile = <<<'PODFILE'
platform :ios, '15.0'
use_frameworks!

target 'NativePHP' do
  # NATIVEPHP_PLUGIN_PODS
end

post_install do |installer|
  installer.pods_project.targets.each do |target|
    target.build_configurations.each do |config|
      config.build_settings['IPHONEOS_DEPLOYMENT_TARGET'] = '15.0'
    end
  end
end
PODFILE;

        $this->files->put($podfilePath, $podfile);
    }

    /**
     * Update Xcode project to include plugin files
     */
    protected function updateXcodeProject(Collection $plugins): void
    {
        $projectPath = $this->iosProjectPath.'/NativePHP.xcodeproj/project.pbxproj';

        if (! $this->files->exists($projectPath)) {
            return;
        }

        // Note: Modifying pbxproj is complex. For a robust solution,
        // consider using a tool like xcodeproj (Ruby) or PBXProj parser.
        //
        // For now, we'll add files to a group that's already set up to
        // include all files in the Plugins directory automatically.
        //
        // Alternative approach: Use a folder reference in Xcode that
        // automatically includes all files in the Plugins directory.

        // The simplest approach is to ensure the Xcode project has a
        // folder reference to Bridge/Plugins/ which auto-includes files.

        $this->ensurePluginsFolderReference($projectPath);
    }

    /**
     * Ensure Xcode project has folder reference for plugins
     */
    protected function ensurePluginsFolderReference(string $projectPath): void
    {
        // This is a placeholder for more complex Xcode project manipulation
        // In practice, you may want to:
        // 1. Use a build phase script that compiles all .swift files in Plugins/
        // 2. Use a folder reference in Xcode
        // 3. Use CocoaPods or SPM for plugin distribution

        // For now, we'll rely on the folder reference approach
        // which requires one-time Xcode project setup
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

    /**
     * Get list of generated Swift files for Xcode
     */
    public function getGeneratedFiles(): array
    {
        if (! $this->files->isDirectory($this->generatedPath)) {
            return [];
        }

        return collect($this->files->allFiles($this->generatedPath))
            ->filter(fn ($file) => $file->getExtension() === 'swift')
            ->map(fn ($file) => $file->getPathname())
            ->values()
            ->all();
    }
}
