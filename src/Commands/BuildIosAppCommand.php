<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Native\Mobile\Plugins\Compilers\IOSPluginCompiler;
use Native\Mobile\Plugins\PluginHookRunner;
use Native\Mobile\Plugins\PluginRegistry;
use Native\Mobile\Plugins\PluginSecretsValidator;
use Native\Mobile\Traits\ChecksLatestBuildNumber;
use Native\Mobile\Traits\CleansEnvFile;
use Native\Mobile\Traits\DisplaysMarketingBanners;
use Native\Mobile\Traits\InstallsAppIcon;
use Native\Mobile\Traits\InstallsSplashScreen;
use Native\Mobile\Traits\ValidatesAppConfig;

use function Laravel\Prompts\error;

class BuildIosAppCommand extends Command
{
    use ChecksLatestBuildNumber, CleansEnvFile, DisplaysMarketingBanners, InstallsAppIcon, InstallsSplashScreen, ValidatesAppConfig;

    private bool $verbose;

    private string $appPath;

    private string $basePath;

    private string $containerPath;

    private string $logPath;

    private ?string $target;

    private string $xcodeProjectPath;

    protected $signature = 'native:build {--target=} {--release} {--simulated} {--no-tty} {--cleanup-provisioning-profile : Clean up CI provisioning profile settings}
        {--upload-to-app-store : Upload iOS app to App Store Connect after packaging}
        {--jump-by= : Add extra number to the suggested version (e.g. --jump-by=10 to skip ahead)}
        {--api-key= : Path to App Store Connect API key file (iOS)}
        {--api-key-path= : Path to App Store Connect API key file (.p8) - same as --api-key}
        {--api-key-id= : App Store Connect API key ID}
        {--api-issuer-id= : App Store Connect API issuer ID}';

    public function handle(): int|string
    {
        $this->basePath = base_path('nativephp/ios');
        $this->logPath = base_path('nativephp/ios-build.log');

        $this->containerPath = $this->basePath.'/NativePHP/';
        $this->appPath = $this->basePath.'/laravel/';
        $this->xcodeProjectPath = $this->basePath.'/NativePHP.xcodeproj';

        $this->target = $this->option('target');

        $this->verbose = getenv('SHELL_VERBOSITY', $this->getOutput()->getVerbosity());

        // Handle cleanup option if requested
        if ($this->option('cleanup-provisioning-profile')) {
            $this->cleanupProvisioningProfileConfiguration();

            return Command::SUCCESS;
        }

        // Clear the last log
        file_put_contents($this->logPath, '');

        $this->bundleLaravelApp();

        if (! getenv('NATIVEPHP_XCODE_BUILD')) {
            // We can't configure inside of Xcode as changing the project files will interrupt the build process
            if (! $this->configureXcodeProject()) {
                return Command::FAILURE;
            }

            return $this->build() ? Command::SUCCESS : Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function bundleLaravelApp(): void
    {
        @mkdir($this->appPath, 0755, true);

        $this->copyLaravelAppIntoIosApp();

        // Set ASSET_URL in .env
        file_put_contents($this->appPath.'.env', PHP_EOL.'ASSET_URL="/_assets"'.PHP_EOL, FILE_APPEND);

        $this->components->task('Installing Composer dependencies', function () {
            Process::path($this->appPath)
                ->forever()
                ->run([
                    'composer',
                    'install',
                    ...($this->option('release') ? ['--no-dev'] : []),
                ], function ($type, $output) {
                    file_put_contents($this->logPath, $output, FILE_APPEND);

                    if ($this->verbose) {
                        $this->output->write($output);
                    }
                });
        });

        $this->components->task('Removing unnecessary files', fn () => $this->removeUnnecessaryFiles());
        $this->cleanEnvFile($this->appPath.'.env');
        $this->createAppZip();
    }

    private function configureXcodeProject(): bool
    {
        $this->updateAppVersion();
        $this->updateBuildNumber();
        $this->setAppName();
        $this->updateInfoPlistFiles();
        $this->configureDeviceOrientations();
        $this->updateEntitlementsFile();
        $this->configureProvisioningProfile();
        $this->installIosIcon();
        $this->installIosSplashScreen();
        $this->installGoogleServicesPlist();

        // Compile plugins AFTER core config so plugin entries (like info_plist)
        // aren't overwritten by updateInfoPlistFiles()
        if (! $this->compileIosPlugins()) {
            return false;
        }

        // Install CocoaPods AFTER plugins have added their dependencies to Podfile
        $this->installCocoaPods();

        // Resolve Swift Package Manager dependencies AFTER plugins have added them
        $this->resolveSwiftPackages();

        return true;
    }

    private function copyLaravelAppIntoIosApp()
    {
        $destination = $this->appPath;

        // Make sure we clear out any old version
        shell_exec("rm -rf {$destination}/*");

        $source = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        $visitedRealPaths = [];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $source,
                \RecursiveDirectoryIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();

            // Skip if we've already visited this real path (prevents infinite loops from circular symlinks)
            if ($realPath === false || isset($visitedRealPaths[$realPath])) {
                continue;
            }

            $visitedRealPaths[$realPath] = true;
            $files[] = $file;
        }

        foreach ($files as $file) {
            // Where the *link* lives (keeps relative paths correct)
            $logicalPath = str_replace('\\', '/', $file->getPathname());
            // Where the link **points** (or the same file if not a link)
            $realPath = str_replace('\\', '/', $file->getRealPath());

            $relativePath = ltrim(substr($logicalPath, strlen($source)), '/');

            if (Str::startsWith($relativePath, 'vendor/nativephp/mobile/resources') ||
                Str::startsWith($relativePath, 'vendor/nativephp/mobile/vendor') ||
                Str::startsWith($relativePath, 'nativephp') ||
                Str::startsWith($relativePath, 'output/') ||
                Str::startsWith($relativePath, 'build/') ||
                Str::startsWith($relativePath, 'dist/') ||
                Str::startsWith($relativePath, 'artifacts/') ||
                Str::startsWith($relativePath, '.git/') ||
                Str::startsWith($relativePath, 'storage/logs/') ||
                Str::startsWith($relativePath, 'storage/framework/cache/')) {
                continue;
            }

            @File::makeDirectory(dirname($destination.$relativePath), recursive: true, force: true);
            @File::copy($realPath, $destination.$relativePath);
        }
    }

    private function updateAppVersion(): void
    {
        $appVersion = config('nativephp.version');

        Process::path($this->xcodeProjectPath)
            ->run([
                'sed', '-i', null,
                "s|MARKETING_VERSION = [^;]*;|MARKETING_VERSION = {$appVersion};|g",
                'project.pbxproj',
            ]);
    }

    private function updateBuildNumber(): void
    {
        // Only increment build number for actual packaging/release builds that will be uploaded
        // Skip for: device runs, simulator builds, cleanup operations, debug builds
        $shouldIncrementBuildNumber = $this->option('release') &&
                                     ! $this->option('target') &&
                                     ! $this->option('cleanup-provisioning-profile');

        // Get current build number from config
        $currentBuildNumber = config('nativephp.version_code');

        if ($shouldIncrementBuildNumber && $this->option('upload-to-app-store')) {
            $jumpBy = (int) $this->option('jump-by') ?: 0;
            $updated = $this->updateBuildNumberFromStore('ios', $jumpBy);
            if ($updated) {
                $currentBuildNumber = config('nativephp.version_code');
                $shouldIncrementBuildNumber = false;
            }
        }

        if (! $currentBuildNumber) {
            $currentBuildNumber = 1;
            if ($shouldIncrementBuildNumber) {
                $this->updateEnvFile('NATIVEPHP_APP_VERSION_CODE', $currentBuildNumber);
            }
        } else {
            if ($shouldIncrementBuildNumber) {
                $currentBuildNumber = (int) $currentBuildNumber + 1;
                $this->updateEnvFile('NATIVEPHP_APP_VERSION_CODE', $currentBuildNumber);
            }
        }

        // Update CFBundleVersion (build number) in Xcode project
        Process::path($this->xcodeProjectPath)
            ->run([
                'sed', '-i', null,
                "s|CURRENT_PROJECT_VERSION = [^;]*;|CURRENT_PROJECT_VERSION = {$currentBuildNumber};|g",
                'project.pbxproj',
            ]);
    }

    private function updateEnvFile(string $key, $value): void
    {
        $envFilePath = base_path('.env');

        if (! file_exists($envFilePath)) {
            return;
        }

        $envContent = file_get_contents($envFilePath);
        $newLine = "{$key}={$value}";

        // Check if the key already exists
        if (preg_match("/^{$key}=.*$/m", $envContent)) {
            // Update existing line
            $envContent = preg_replace("/^{$key}=.*$/m", $newLine, $envContent);
        } else {
            // Add new line
            $envContent = PHP_EOL.rtrim($envContent).PHP_EOL.$newLine.PHP_EOL;
        }

        file_put_contents($envFilePath, $envContent);
    }

    protected function setAppName(): void
    {
        $this->components->task('Configuring Xcode project', function () {
            $name = config('app.name');
            $bundleId = config('nativephp.app_id');

            $this->updateDisplayName($name);
            $this->updateBundleIdForTarget($bundleId);
        });
    }

    private function updateDisplayName(string $name): void
    {
        $quoted = '"'.$name.'"';

        $escaped = strtr($quoted, [
            '\\' => '\\\\',
            '&' => '\\&',
            '|' => '\\|',
        ]);

        $script = 's|INFOPLIST_KEY_CFBundleDisplayName[[:space:]]*=[[:space:]]*[^;]*;|'
            ."INFOPLIST_KEY_CFBundleDisplayName = {$escaped};|g";

        Process::path($this->xcodeProjectPath)
            ->run([
                'sed', '-i', null,
                $script,
                'project.pbxproj',
            ]);
    }

    private function updateBundleIdForTarget(string $bundleId): void
    {
        Process::path($this->xcodeProjectPath)
            ->run([
                'sed', '-i', null, '-E',
                "/com\\.nativephp\\.(NativePHPTests|NativePHPUITests)/! s/(PRODUCT_BUNDLE_IDENTIFIER = ).*(;)/\\1{$bundleId}\\2/",
                'project.pbxproj',
            ]);
    }

    /**
     * Update the Info.plist files with the correct app_id and deeplink_scheme values from the config.
     */
    private function updateInfoPlistFiles(): void
    {
        $this->components->task('Updating Info.plist files', function () {
            $appId = config('nativephp.app_id');
            $deeplinkScheme = config('nativephp.deeplink_scheme');

            $regularInfoPlist = $this->containerPath.'Info.plist';
            if (file_exists($regularInfoPlist)) {
                $this->updateInfoPlistFile($regularInfoPlist, $appId, $deeplinkScheme);
            }

            $simulatorInfoPlist = $this->basePath.'/NativePHP-simulator-Info.plist';
            if (file_exists($simulatorInfoPlist)) {
                $this->updateInfoPlistFile($simulatorInfoPlist, $appId, $deeplinkScheme);
            }
        });
    }

    /**
     * Configure device orientations and targeting by updating the Xcode project settings directly.
     * Sets TARGETED_DEVICE_FAMILY and INFOPLIST_KEY_UISupportedInterfaceOrientations in project.pbxproj.
     */
    private function configureDeviceOrientations(): void
    {
        $orientationConfig = config('nativephp.orientation', []);
        // Support both 'iPad' and 'ipad' as keys
        $iPadEnabled = config('nativephp.iPad', config('nativephp.ipad', false));

        // Map configuration keys to iOS orientation constants
        $orientationMap = [
            'portrait' => 'UIInterfaceOrientationPortrait',
            'upside_down' => 'UIInterfaceOrientationPortraitUpsideDown',
            'landscape_left' => 'UIInterfaceOrientationLandscapeLeft',
            'landscape_right' => 'UIInterfaceOrientationLandscapeRight',
        ];

        // Collect enabled orientations for each device type
        $iPhoneOrientations = [];
        $iPadOrientations = [];

        // Support both 'iPhone' and 'iphone' as keys
        $iPhoneConfig = $orientationConfig['iPhone'] ?? $orientationConfig['iphone'] ?? [];
        if (! empty($iPhoneConfig)) {
            foreach ($iPhoneConfig as $orientation => $enabled) {
                if ($enabled && isset($orientationMap[$orientation])) {
                    $iPhoneOrientations[] = $orientationMap[$orientation];
                }
            }
        }

        // If iPad is enabled, set all orientations (required by Apple)
        if ($iPadEnabled) {
            $iPadOrientations = [
                'UIInterfaceOrientationPortrait',
                'UIInterfaceOrientationPortraitUpsideDown',
                'UIInterfaceOrientationLandscapeLeft',
                'UIInterfaceOrientationLandscapeRight',
            ];
        }

        // Validate that at least iPhone has orientations enabled
        if (empty($iPhoneOrientations) && empty($iPadOrientations)) {
            throw new \Exception('All orientations are disabled for iPhone and iPad is disabled. At least iPhone must have orientations enabled or iPad must be enabled.');
        }

        $pbxPath = $this->xcodeProjectPath.'/project.pbxproj';

        // Determine device family targeting
        $deviceFamily = '';
        if (! empty($iPhoneOrientations) && ! empty($iPadOrientations)) {
            $deviceFamily = '"1,2"'; // Both iPhone and iPad
        } elseif (! empty($iPhoneOrientations)) {
            $deviceFamily = '1'; // iPhone only
        } else {
            $deviceFamily = '2'; // iPad only
        }

        // Update TARGETED_DEVICE_FAMILY
        $this->updateProjectSetting($pbxPath, 'TARGETED_DEVICE_FAMILY', $deviceFamily);

        // Remove any existing orientation keys first (including conflicting ones)
        $this->removeProjectSetting($pbxPath, 'INFOPLIST_KEY_UISupportedInterfaceOrientations');
        $this->removeProjectSetting($pbxPath, 'INFOPLIST_KEY_UISupportedInterfaceOrientations_iPad');
        $this->removeProjectSetting($pbxPath, 'INFOPLIST_KEY_UISupportedInterfaceOrientations_iPhone');

        // Check if iPhone and iPad have the same orientations
        $orientationsMatch = (count($iPhoneOrientations) === count($iPadOrientations)) &&
                           (count(array_intersect($iPhoneOrientations, $iPadOrientations)) === count($iPhoneOrientations));

        if ($orientationsMatch && ! empty($iPhoneOrientations) && ! empty($iPadOrientations)) {
            $orientationString = '"'.implode(' ', $iPhoneOrientations).'"';
            $this->updateProjectSetting($pbxPath, 'INFOPLIST_KEY_UISupportedInterfaceOrientations', $orientationString);
        } else {
            if (! empty($iPhoneOrientations)) {
                $iPhoneOrientationString = '"'.implode(' ', $iPhoneOrientations).'"';
                $this->updateProjectSetting($pbxPath, 'INFOPLIST_KEY_UISupportedInterfaceOrientations', $iPhoneOrientationString);
            }

            if (! empty($iPadOrientations)) {
                $iPadOrientationString = '"'.implode(' ', $iPadOrientations).'"';
                $this->updateProjectSetting($pbxPath, 'INFOPLIST_KEY_UISupportedInterfaceOrientations_iPad', $iPadOrientationString);
            }
        }

        $this->validateProjectSettings($pbxPath, $deviceFamily, $iPhoneOrientations, $iPadOrientations);
    }

    /**
     * Update a project setting in ALL buildSettings sections of the Xcode project.
     */
    private function updateProjectSetting(string $pbxPath, string $key, string $value): void
    {
        // First remove any existing entries for this key
        $this->removeProjectSetting($pbxPath, $key);

        // Add the new setting to ALL buildSettings sections
        // BSD sed (macOS) doesn't support multiline mode flag 'm'
        // Use address range pattern instead: /start/,/end/
        $result = Process::run(['sed', '-i', '', '-E',
            '/buildSettings = \{/,/^\t\t\t\}; *$/ s/(^\t\t\t\}; *$)/\\t\\t\\t\\t'.$key.' = '.$value.';\\
\\1/',
            $pbxPath,
        ]);

    }

    /**
     * Remove a project setting from ALL buildSettings sections in the Xcode project.
     */
    private function removeProjectSetting(string $pbxPath, string $key): void
    {
        // Remove all instances of the key, including duplicates
        // This regex matches the key followed by optional spaces, =, any value, and semicolon
        Process::run(['sed', '-i', '', '-E', "/{$key}[[:space:]]*=.*;/d", $pbxPath]);
        Process::run(['sed', '-i', '', '-E', "/{$key}[[:space:]]*$/d", $pbxPath]);
    }

    /**
     * Validate that project settings were correctly applied to all buildSettings sections.
     */
    private function validateProjectSettings(string $pbxPath, string $deviceFamily, array $iPhoneOrientations, array $iPadOrientations): void
    {
        $pbxContent = file_get_contents($pbxPath);
        if (! $pbxContent) {
            return;
        }

        $buildSettingsCount = preg_match_all('/buildSettings = \{/', $pbxContent);
        $orientationCount = preg_match_all('/INFOPLIST_KEY_UISupportedInterfaceOrientations.*=.*;/', $pbxContent);

        if ($orientationCount === 0) {
            error('No orientation settings found in any buildSettings section');
        }
    }

    /**
     * Update a specific Info.plist file with the given app_id and deeplink_scheme.
     * Also removes UIBackgroundModes if push notifications are disabled.
     *
     * Uses DOMDocument for proper handling of plist key-value pairs as siblings.
     */
    private function updateInfoPlistFile(string $filePath, string $appId, ?string $deeplinkScheme): void
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (! $dom->load($filePath)) {
            $this->error("Failed to load file: {$filePath}");

            return;
        }

        try {
            $pushNotificationsEnabled = config('nativephp.permissions.push_notifications', false);

            // Find the root dict element
            $rootDict = $dom->getElementsByTagName('dict')->item(0);
            if (! $rootDict) {
                $this->error("No root dict found in: {$filePath}");

                return;
            }

            // Parse plist into key-value pairs for easier manipulation
            $plistData = $this->parsePlistDict($rootDict);

            // Update CFBundleURLTypes
            if (isset($plistData['CFBundleURLTypes'])) {
                $this->updateUrlTypes($plistData['CFBundleURLTypes']['valueNode'], $appId, $deeplinkScheme);
            }

            // Handle UIBackgroundModes
            $this->updateBackgroundModes($rootDict, $plistData, $pushNotificationsEnabled);

            // Handle BIFROST_APP_ID
            $bifrostAppId = env('BIFROST_APP_ID');
            if ($bifrostAppId) {
                if (isset($plistData['BIFROST_APP_ID'])) {
                    $plistData['BIFROST_APP_ID']['valueNode']->nodeValue = $bifrostAppId;
                } else {
                    $this->addPlistKeyValue($dom, $rootDict, 'BIFROST_APP_ID', 'string', $bifrostAppId);
                }
            }

            // Save the updated XML
            $xmlContent = $dom->saveXML();

            // Insert DOCTYPE after the XML declaration if not already present
            if (! str_contains($xmlContent, '<!DOCTYPE')) {
                $xmlContent = preg_replace(
                    '/<\?xml.*?\?>\s*/s',
                    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n",
                    $xmlContent
                );
            }

            file_put_contents($filePath, $xmlContent);
        } catch (\Exception $e) {
            $this->error("Error updating {$filePath}: ".$e->getMessage());
        }
    }

    /**
     * Parse a plist dict element into an associative array of key => [keyNode, valueNode]
     */
    private function parsePlistDict(\DOMElement $dict): array
    {
        $result = [];
        $children = [];

        // Collect all element children (skip text nodes)
        foreach ($dict->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $children[] = $child;
            }
        }

        // Parse key-value pairs (keys alternate with values)
        for ($i = 0; $i < count($children); $i++) {
            if ($children[$i]->nodeName === 'key' && isset($children[$i + 1])) {
                $keyName = $children[$i]->nodeValue;
                $result[$keyName] = [
                    'keyNode' => $children[$i],
                    'valueNode' => $children[$i + 1],
                ];
                $i++; // Skip the value node
            }
        }

        return $result;
    }

    /**
     * Update CFBundleURLTypes with app_id and deeplink_scheme
     */
    private function updateUrlTypes(\DOMElement $urlTypesArray, string $appId, ?string $deeplinkScheme): void
    {
        $dicts = $urlTypesArray->getElementsByTagName('dict');
        if ($dicts->length === 0) {
            return;
        }

        $urlTypeDict = $dicts->item(0);
        $urlTypeData = $this->parsePlistDict($urlTypeDict);

        // Update CFBundleURLName
        if (isset($urlTypeData['CFBundleURLName'])) {
            $urlTypeData['CFBundleURLName']['valueNode']->nodeValue = $appId;
        }

        // Update CFBundleURLSchemes
        if (isset($urlTypeData['CFBundleURLSchemes'])) {
            $schemesArray = $urlTypeData['CFBundleURLSchemes']['valueNode'];

            // Remove all existing string children
            while ($schemesArray->firstChild) {
                $schemesArray->removeChild($schemesArray->firstChild);
            }

            // Add the deeplink scheme if configured
            if (! empty($deeplinkScheme)) {
                $stringNode = $schemesArray->ownerDocument->createElement('string', $deeplinkScheme);
                $schemesArray->appendChild($stringNode);
            }
        }
    }

    /**
     * Update UIBackgroundModes based on enabled features
     *
     * Note: Plugins can declare UIBackgroundModes in their nativephp.json.
     * All plugin-declared modes are preserved. Core only manages 'remote-notification'
     * based on push notification settings.
     */
    private function updateBackgroundModes(\DOMElement $rootDict, array &$plistData, bool $pushEnabled): void
    {
        $modesToKeep = [];

        // Check existing modes (may have been added by plugins via IOSPluginCompiler)
        if (isset($plistData['UIBackgroundModes'])) {
            $modesArray = $plistData['UIBackgroundModes']['valueNode'];
            $strings = $modesArray->getElementsByTagName('string');

            foreach ($strings as $string) {
                $mode = $string->nodeValue;

                if ($mode === 'remote-notification' && $pushEnabled) {
                    $modesToKeep[] = $mode;
                } elseif ($mode !== 'remote-notification') {
                    // Keep all other modes (plugin-declared ones like audio, location, etc.)
                    $modesToKeep[] = $mode;
                }
            }
        }

        // Add remote-notification if enabled in config but not present
        if ($pushEnabled && ! in_array('remote-notification', $modesToKeep)) {
            $modesToKeep[] = 'remote-notification';
        }

        // Remove or update UIBackgroundModes
        if (empty($modesToKeep)) {
            // Remove the key-value pair entirely
            if (isset($plistData['UIBackgroundModes'])) {
                $this->removePlistKeyValue(
                    $rootDict,
                    $plistData['UIBackgroundModes']['keyNode'],
                    $plistData['UIBackgroundModes']['valueNode']
                );
                unset($plistData['UIBackgroundModes']);
            }
        } else {
            if (isset($plistData['UIBackgroundModes'])) {
                // Update existing array
                $modesArray = $plistData['UIBackgroundModes']['valueNode'];
                while ($modesArray->firstChild) {
                    $modesArray->removeChild($modesArray->firstChild);
                }
                foreach ($modesToKeep as $mode) {
                    $stringNode = $modesArray->ownerDocument->createElement('string', $mode);
                    $modesArray->appendChild($stringNode);
                }
            } else {
                // Create new UIBackgroundModes
                $dom = $rootDict->ownerDocument;
                $keyNode = $dom->createElement('key', 'UIBackgroundModes');
                $arrayNode = $dom->createElement('array');
                foreach ($modesToKeep as $mode) {
                    $stringNode = $dom->createElement('string', $mode);
                    $arrayNode->appendChild($stringNode);
                }
                $rootDict->appendChild($keyNode);
                $rootDict->appendChild($arrayNode);
            }
        }
    }

    /**
     * Remove a plist key-value pair from the dict
     */
    private function removePlistKeyValue(\DOMElement $dict, \DOMElement $keyNode, \DOMElement $valueNode): void
    {
        $dict->removeChild($valueNode);
        $dict->removeChild($keyNode);
    }

    /**
     * Add a plist key-value pair to the dict
     */
    private function addPlistKeyValue(\DOMDocument $dom, \DOMElement $dict, string $key, string $valueType, string $value): void
    {
        $keyNode = $dom->createElement('key', $key);
        $valueNode = $dom->createElement($valueType, $value);
        $dict->appendChild($keyNode);
        $dict->appendChild($valueNode);
    }

    /**
     * Update the NativePHP.entitlements file with the correct deeplink_host value from the config
     * and remove push notifications if disabled.
     */
    private function updateEntitlementsFile(): void
    {

        $deeplinkHost = config('nativephp.deeplink_host');
        $pushNotificationsEnabled = config('nativephp.permissions.push_notifications', false);
        $nfcEnabled = config('nativephp.permissions.nfc', false);

        $entitlementsFile = $this->containerPath.'NativePHP.entitlements';

        // Create clean entitlements file from scratch to avoid XML parsing issues
        if ($pushNotificationsEnabled) {
            $apsEnvironment = $this->determineApsEnvironment();

            $entitlementsContent = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>aps-environment</key>
	<string>'.$apsEnvironment.'</string>';

            // Add associated domains if deeplink host is configured
            if (! empty($deeplinkHost)) {
                $entitlementsContent .= '
	<key>com.apple.developer.associated-domains</key>
	<array>
		<string>applinks:'.$deeplinkHost.'</string>
	</array>';
            }

            // Add NFC entitlement if enabled (compatible format for iOS 18.2+)
            if ($nfcEnabled) {
                $entitlementsContent .= '
	<key>com.apple.developer.nfc.readersession.formats</key>
	<array>
		<string>TAG</string>
	</array>';
            }

            $entitlementsContent .= '
</dict>
</plist>';

            file_put_contents($entitlementsFile, $entitlementsContent);
        } else {
            // Create minimal entitlements with just associated domains if needed
            $entitlementsContent = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>';

            if (! empty($deeplinkHost)) {
                $entitlementsContent .= '
	<key>com.apple.developer.associated-domains</key>
	<array>
		<string>applinks:'.$deeplinkHost.'</string>
	</array>';
            }

            // Add NFC entitlement if enabled (compatible format for iOS 18.2+)
            if ($nfcEnabled) {
                $entitlementsContent .= '
	<key>com.apple.developer.nfc.readersession.formats</key>
	<array>
		<string>TAG</string>
	</array>';
            }

            $entitlementsContent .= '
</dict>
</plist>';

            file_put_contents($entitlementsFile, $entitlementsContent);
        }
    }

    private function installGoogleServicesPlist(): void
    {
        $path = base_path('nativephp/resources/GoogleService-Info.plist');

        if (! file_exists($path)) {
            $path = base_path('GoogleService-Info.plist');
        }

        if (! file_exists($path)) {
            return;
        }

        $destinationPath = $this->containerPath.'GoogleService-Info.plist';
        @copy($path, $destinationPath);
    }

    private function removeUnnecessaryFiles(): void
    {
        $directoriesToRemove = array_merge(
            config('nativephp.cleanup_exclude_files') ?? [],
            [
                '.git',
                '.github',
                'node_modules',
                'vendor/bin',
                'tests',
                'storage/logs',
                'storage/framework',
                'vendor/laravel/pint/builds',
                'public/storage',
            ]
        );

        foreach ($directoriesToRemove as $dir) {
            if (is_dir($this->appPath.$dir)) {
                File::deleteDirectory($this->appPath.$dir);
            }
        }

        $filesToRemove = [
            'database/database.sqlite',
            '*.js',
            '*.md',
            '*.lock',
            '*.xml',
            '.env.example',
            'artisan',
            '.gitignore',
            '.gitattributes',
            '.gitkeep',
            '.editorconfig',
            '.DS_Store',
            'vendor/livewire/livewire/src/Features/SupportFileUploads/browser_test_image_big.jpg',
        ];

        foreach ($filesToRemove as $pattern) {
            $files = glob($this->appPath.$pattern);
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function determineApsEnvironment(): string
    {
        // Check if we're in a package command with export method
        $exportMethod = env('NATIVEPHP_EXPORT_METHOD');

        if ($exportMethod === 'development' || $exportMethod === 'debugging') {
            return 'development';
        }

        // For release builds or production export methods (app-store, ad-hoc, enterprise)
        if ($this->option('release') ||
            in_array($exportMethod, ['app-store', 'ad-hoc', 'enterprise'])) {
            return 'production';
        }

        // Default to development for debug builds
        return 'development';
    }

    private function createAppZip(): void
    {
        $zipPath = $this->containerPath.'app.zip';

        // Remove existing ZIP if it exists
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }

        // Create ZIP from the prepared app directory
        $escapedAppPath = escapeshellarg($this->appPath);
        $escapedZipPath = escapeshellarg($zipPath);

        $command = $this->option('release')
            ? "cd {$escapedAppPath} && zip -9 -r {$escapedZipPath} . -x '*.DS_Store' '*/.*'"
            : "cd {$escapedAppPath} && zip -0 -r {$escapedZipPath} . -x '*.DS_Store' '*/.*'";

        $result = Process::run($command);

        if (! $result->successful()) {
            throw new \Exception('Failed to create ZIP file');
        }

        $this->createBundledVersionFile($zipPath);

        Process::run("rm -rf {$escapedAppPath}");
    }

    private function createBundledVersionFile(string $zipPath): void
    {
        // Get version from Laravel config
        $appVersion = config('nativephp.version', 'DEBUG');

        $versionFilePath = dirname($zipPath).'/bundled.version';
        file_put_contents($versionFilePath, $appVersion);
    }

    private function configureProvisioningProfile(): void
    {
        $provisioningProfileName = getenv('IOS_PROVISIONING_PROFILE_NAME') ?: getenv('EXTRACTED_PROVISIONING_PROFILE_NAME');
        $provisioningProfileUuid = getenv('EXTRACTED_PROVISIONING_PROFILE_UUID');
        $teamId = getenv('IOS_TEAM_ID') ?: config('nativephp.development_team');

        $profileIdentifier = $provisioningProfileUuid ?: $provisioningProfileName;

        if (! $teamId || ! $profileIdentifier) {
            return;
        }

        // Look for the main NativePHP target Release configuration and add PROVISIONING_PROFILE_SPECIFIER
        // We need to find the Release config that has CODE_SIGN_ENTITLEMENTS (which is the main app target)
        $sedScript = '/CODE_SIGN_ENTITLEMENTS = NativePHP\\/NativePHP\\.entitlements;/a\\
				PROVISIONING_PROFILE_SPECIFIER = "'.$profileIdentifier.'";';

        Process::path($this->xcodeProjectPath)
            ->run([
                'sed', '-i', '',
                $sedScript,
                'project.pbxproj',
            ]);
    }

    public function cleanupProvisioningProfileConfiguration(): void
    {
        Process::path($this->xcodeProjectPath)
            ->run([
                'sed', '-i', '',
                '/PROVISIONING_PROFILE_SPECIFIER = "[0-9a-fA-F-]*";/d',
                'project.pbxproj',
            ]);
    }

    private function build(): bool
    {
        $simulated = $this->option('simulated');
        $signingArgs = $this->getSigningArgs($simulated);

        // No target-specific args needed - workspace builds don't support target specification
        $targetSpecificArgs = [];

        // Use CocoaPods workspace if it exists, otherwise fall back to internal workspace
        $workspace = file_exists($this->basePath.'/NativePHP.xcworkspace')
            ? 'NativePHP.xcworkspace'
            : 'NativePHP.xcodeproj/project.xcworkspace';

        // Build the complete command array for logging
        $xcodebuildCommand = [
            'xcodebuild',
            '-scheme', 'NativePHP'.($simulated ? '-simulator' : ''),
            '-workspace', $workspace,
            '-sdk', ($simulated ? 'iphonesimulator' : 'iphoneos'),
            ...($simulated ? [] : ['-configuration', $this->option('release') ? 'Release' : 'Debug']),
            ...$signingArgs,
            // Note: CODE_SIGN_STYLE is already included in $signingArgs, no need to duplicate
            ...$targetSpecificArgs,
            '-derivedDataPath', 'build',
            // Skip Swift Package Manager plugin validation to avoid bundle signing issues
            '-skipPackagePluginValidation',
            // Add verbose output to help debug build failures
            '-verbose',
            ...($this->target ? [
                '-destination', 'id='.$this->target.($simulated ? '' : ',platform=iOS'),
                'build',
            ] : [
                '-archivePath', $this->basePath.'/build/NativePHP.xcarchive',
                'archive',
            ]),
        ];

        $result = Process::path($this->basePath)
            ->forever()
            ->env([
                'NATIVEPHP_CLI_BUILD' => true,
            ])
            ->tty($this->verbose && ! $this->option('no-tty'))
            ->run($xcodebuildCommand, function ($type, $output) {
                file_put_contents($this->logPath, $output, FILE_APPEND);

                if ($this->verbose) {
                    $this->output->write($output);
                }
            });

        if (! $result->successful()) {
            error('Build failed');
            $this->newLine();

            // Always show error output when build fails, regardless of verbose mode
            $errorOutput = $result->errorOutput();
            $standardOutput = $result->output();

            // Try to find specific xcodebuild errors in the output
            $allOutput = $standardOutput."\n".$errorOutput;

            // Look for common error patterns with their solutions
            $errorPatterns = [
                '/Provisioning profile "([^"]+)" doesn\'t include signing certificate "([^"]+)"/' => [
                    'type' => 'certificate_mismatch',
                    'solution' => 'Your provisioning profile and certificate don\'t match. Either:
  1. Regenerate your provisioning profile in Apple Developer Portal to include this certificate
  2. Download and install the correct certificate that matches your profile
  3. Check that your certificate hasn\'t expired',
                ],
                '/No signing certificate "([^"]+)" found/' => [
                    'type' => 'missing_certificate',
                    'solution' => 'The signing certificate is not installed in your keychain. Either:
  1. Download and install the certificate from Apple Developer Portal
  2. For CI: ensure IOS_DISTRIBUTION_CERTIFICATE_PATH and IOS_DISTRIBUTION_CERTIFICATE_PASSWORD are set',
                ],
                '/Provisioning profile "([^"]+)" is expired/' => [
                    'type' => 'expired_profile',
                    'solution' => 'Your provisioning profile has expired. Regenerate it in Apple Developer Portal.',
                ],
                '/No profiles for \'([^\']+)\' were found/' => [
                    'type' => 'no_profile',
                    'solution' => 'No provisioning profile found for this bundle ID. Either:
  1. Create a provisioning profile in Apple Developer Portal
  2. For CI: set IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH',
                ],
                '/Code Signing Error: (.+)$/m' => [
                    'type' => 'code_signing',
                    'solution' => 'Check your code signing configuration in Xcode or your CI environment variables.',
                ],
                '/error: (.+)$/m' => [
                    'type' => 'generic',
                    'solution' => null,
                ],
                '/fatal error: (.+)$/m' => [
                    'type' => 'fatal',
                    'solution' => null,
                ],
            ];

            $foundErrors = [];
            $shownSolution = false;

            foreach ($errorPatterns as $pattern => $info) {
                if (preg_match_all($pattern, $allOutput, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $errorMsg = trim($match[0]);
                        if (! in_array($errorMsg, $foundErrors)) {
                            $foundErrors[] = $errorMsg;

                            // Show solution for specific errors (only once)
                            if (! $shownSolution && $info['solution']) {
                                $this->newLine();
                                $this->line('<fg=white;bg=red> ERROR </>  '.$errorMsg);
                                $this->newLine();
                                $this->line('<fg=yellow>How to fix:</>');
                                $this->line($info['solution']);
                                $this->newLine();
                                $shownSolution = true;
                            }
                        }
                    }
                }
            }

            // If we didn't show a specific solution, show generic error output
            if (! $shownSolution) {
                if (! empty($foundErrors)) {
                    $this->line('<fg=red>Detected errors:</>');
                    foreach (array_unique($foundErrors) as $errorLine) {
                        $this->line('  • '.$errorLine);
                    }
                    $this->newLine();
                }

                // Show the last portion of output which usually contains the error
                if ($errorOutput) {
                    $this->line('<fg=yellow>Error output (last 30 lines):</>');
                    $errorLines = explode("\n", trim($errorOutput));
                    $lastErrors = array_slice($errorLines, -30);
                    foreach ($lastErrors as $line) {
                        $this->line($line);
                    }
                    $this->newLine();
                }
            }

            $this->line('<fg=yellow>Build log saved to:</> '.$this->logPath);
            $this->line('<fg=gray>Run with -v for full build output.</>');

            return false;
        }

        \Laravel\Prompts\outro('App build succeeded!');

        // Run post-build hooks for all plugins
        $this->runPostBuildHooks();

        return true;
    }

    private function validateInfoPlistXml(string $filePath): void
    {
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            throw new \Exception('Failed to parse XML structure');
        }
    }

    private function getSigningArgs(bool $simulated): array
    {
        if ($simulated) {
            return [];
        }

        if ($this->option('target')) {
            return ['-allowProvisioningUpdates'];
        }

        $teamId = getenv('IOS_TEAM_ID') ?: config('nativephp.development_team');
        $provisioningProfileName = getenv('EXTRACTED_PROVISIONING_PROFILE_NAME');
        $tempKeychainPath = getenv('NATIVEPHP_TEMP_KEYCHAIN_PATH');

        $certPath = env('IOS_DISTRIBUTION_CERTIFICATE_PATH');
        $provisioningPath = env('IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH');

        $hasFileBasedCreds = $certPath && $provisioningPath && $teamId;
        $hasTempKeychain = $tempKeychainPath && $teamId;

        if ($hasTempKeychain || $hasFileBasedCreds) {
            return [
                'CODE_SIGN_STYLE=Manual',
                "DEVELOPMENT_TEAM={$teamId}",
                'CODE_SIGN_IDENTITY=Apple Distribution',
            ];
        }

        if ($certPath || $provisioningPath) {
            throw new \Exception('Incomplete CI signing configuration');
        }

        return ['-allowProvisioningUpdates'];
    }

    /**
     * Compile iOS plugins by copying native code and generating bridge registrations.
     *
     * @return bool True if compilation succeeded, false if it failed
     */
    private function compileIosPlugins(): bool
    {
        $plugins = app(PluginRegistry::class);

        if ($plugins->count() === 0) {
            return true;
        }

        // Validate plugin secrets before compilation
        $secretsValidator = new PluginSecretsValidator($plugins->all());
        $secretsValidator->setOutput($this->output);
        $result = $secretsValidator->validate();

        if (! $result['valid']) {
            $this->newLine();
            error('Missing required plugin secrets:');
            $this->newLine();

            foreach ($result['missing'] as $missing) {
                $this->components->twoColumnDetail(
                    "<fg=yellow>{$missing['secret']}</>",
                    "<fg=gray>{$missing['description']}</>"
                );
            }

            $this->newLine();
            $this->line('<fg=gray>Add these to your .env file and try again.</>');

            return false;
        }

        try {
            $compiler = app(IOSPluginCompiler::class);
            $compiler->setOutput($this->output)
                ->setAppId(config('nativephp.app_id', ''))
                ->setConfig([
                    'version' => config('nativephp.version'),
                    'version_code' => config('nativephp.version_code'),
                    'release' => $this->option('release'),
                ]);

            foreach ($plugins->all() as $plugin) {
                $this->components->twoColumnDetail('<fg=blue>Compiling plugin</>', "{$plugin->name} ({$plugin->version})");
            }

            $compiler->compile();

            return true;
        } catch (\Exception $e) {
            $this->error("❌ Plugin compilation failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Run post-build hooks for all plugins.
     */
    private function runPostBuildHooks(): void
    {
        $plugins = app(PluginRegistry::class);

        if ($plugins->count() === 0) {
            return;
        }

        $hookRunner = new PluginHookRunner(
            platform: 'ios',
            buildPath: $this->basePath,
            appId: config('nativephp.app_id', ''),
            config: [
                'version' => config('nativephp.version'),
                'version_code' => config('nativephp.version_code'),
                'release' => $this->option('release'),
            ],
            plugins: $plugins->all(),
            output: $this->output
        );

        $hookRunner->runPostBuildHooks();
    }

    /**
     * Install CocoaPods dependencies if Podfile exists.
     */
    private function installCocoaPods(): void
    {
        $podfilePath = $this->basePath.'/Podfile';

        if (! file_exists($podfilePath)) {
            return;
        }

        $this->components->task('Installing CocoaPods dependencies', function () {
            $result = Process::path($this->basePath)
                ->timeout(300)
                ->run(['pod', 'install'], function ($type, $output) {
                    file_put_contents($this->logPath, $output, FILE_APPEND);

                    if ($this->verbose) {
                        $this->output->write($output);
                    }
                });

            if (! $result->successful()) {
                $error = $result->errorOutput() ?: $result->output();
                throw new \Exception($error ?: 'pod install failed with exit code '.$result->exitCode());
            }

            return true;
        });
    }

    /**
     * Resolve Swift Package Manager dependencies.
     *
     * This downloads and resolves SPM packages that were injected into the project.pbxproj
     * by the plugin compiler.
     */
    private function resolveSwiftPackages(): void
    {
        $projectPath = $this->basePath.'/NativePHP.xcodeproj/project.pbxproj';

        // Only run if we have SPM packages (check for XCRemoteSwiftPackageReference)
        if (! file_exists($projectPath)) {
            return;
        }

        $projectContent = file_get_contents($projectPath);
        if (! str_contains($projectContent, 'XCRemoteSwiftPackageReference')) {
            return;
        }

        $this->components->task('Resolving Swift Package dependencies', function () {
            $result = Process::path($this->basePath)
                ->timeout(300)
                ->run([
                    'xcodebuild',
                    '-resolvePackageDependencies',
                    '-project', 'NativePHP.xcodeproj',
                ], function ($type, $output) {
                    file_put_contents($this->logPath, $output, FILE_APPEND);

                    if ($this->verbose) {
                        $this->output->write($output);
                    }
                });

            return $result->successful();
        });
    }
}
