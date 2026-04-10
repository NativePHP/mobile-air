<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Native\Mobile\Plugins\Compilers\AndroidPluginCompiler;
use Native\Mobile\Plugins\PluginHookRunner;
use Native\Mobile\Plugins\PluginRegistry;
use Native\Mobile\Plugins\PluginSecretsValidator;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

trait RunsAndroid
{
    use PreparesBuild, WatchesAndroid;

    protected string $androidLogPath = 'nativephp'.DIRECTORY_SEPARATOR.'android-build.log';

    /**
     * Write a message to the Android build log file
     */
    protected function logToFile(string $message): void
    {
        if (! isset($this->androidLogPath)) {
            return;
        }

        $timestamp = now()->format('Y-m-d H:i:s');
        file_put_contents($this->androidLogPath, "[$timestamp] $message\n", FILE_APPEND);
    }

    /**
     * @throws \Exception
     */
    public function runAndroid(): void
    {
        $this->androidLogPath = base_path($this->androidLogPath);

        // Clear the last log
        file_put_contents($this->androidLogPath, '');

        // Show log path first thing so users can tail -f
        note("Build log: {$this->androidLogPath}");

        // Log build header
        $this->logToFile('=== NativePHP Android Build Started ===');
        $this->logToFile('PHP Version: '.PHP_VERSION);
        $this->logToFile('OS: '.PHP_OS_FAMILY);
        $this->logToFile('Working Directory: '.base_path());
        $this->logToFile('Build Type: '.$this->buildType);

        $androidPath = base_path('nativephp/android');

        if (! is_dir($androidPath)) {
            $this->logToFile('ERROR: No Android project found at [nativephp/android]');
            error('No Android project found at [nativephp/android].');
            note('Run `php artisan native:install` or ensure you have the correct folder structure.');

            return;
        }

        $this->logToFile('Android project path: '.$androidPath);

        if (! $this->validateBuildEnvironment()) {
            return;
        }

        $minSdk = (int) config('nativephp.android.min_sdk', 26);
        if ($minSdk < 26) {
            $this->logToFile("ERROR: NATIVEPHP_ANDROID_MIN_SDK is set to $minSdk, but must be at least 26");
            error("NATIVEPHP_ANDROID_MIN_SDK is set to $minSdk, but must be at least 26.");
            note('Android API level 26 (Android 8.0 Oreo) is the minimum version required by NativePHP. Please update your .env or config/nativephp.php.');

            return;
        }

        $plugins = app(PluginRegistry::class)->all();
        foreach ($plugins as $plugin) {
            $pluginMinSdk = $plugin->getAndroidMinVersion();
            if ($pluginMinSdk !== null && $minSdk < $pluginMinSdk) {
                $this->logToFile("ERROR: Plugin '{$plugin->name}' requires Android API level $pluginMinSdk, but NATIVEPHP_ANDROID_MIN_SDK is set to $minSdk");
                error("Plugin '{$plugin->name}' requires Android API level $pluginMinSdk, but your min SDK is $minSdk.");
                note("Your app may crash on devices running Android API levels $minSdk-".($pluginMinSdk - 1).'. Either raise NATIVEPHP_ANDROID_MIN_SDK to at least '.$pluginMinSdk.' in your .env, or remove the plugin.');

                return;
            }
        }

        // Start Vite dev server early if watching, so hot file is present during build
        if ($this->option('watch')) {
            $this->startViteDevServer('android');
        }

        // Only require ADB and emulator selection for debug
        $target = null;
        if ($this->buildType === 'debug') {
            $this->logToFile('Checking ADB availability...');
            if (! $this->canRunCommand('adb version')) {
                $this->logToFile('ERROR: ADB is not installed or not in PATH');
                error('ADB is not installed or not in your PATH.');

                return;
            }
            $this->logToFile('ADB is available');

            $target = $this->argument('udid') ?? $this->promptForAndroidTarget();
            $this->logToFile('Target device: '.$target);
        }

        // Skip Gradle cache clean for debug builds to speed up incremental builds
        // Include dev dependencies for debug builds (like iOS does)
        $cleanCache = $this->buildType !== 'debug';
        $excludeDevDependencies = $this->buildType !== 'debug';

        $this->prepareAndroidBuild($cleanCache, $excludeDevDependencies);

        if (! $this->compileAndroidPlugins()) {
            return;
        }

        $this->runTheAndroidBuild($target);

        if ($this->option('watch')) {
            $this->logToFile('Starting hot reload...');
            $this->startAndroidHotReload();
        }

        $this->logToFile('=== NativePHP Android Build Completed ===');
    }

    private function detectCurrentAppId(): ?string
    {
        $gradlePath = base_path('nativephp/android/app/build.gradle.kts');

        if (! File::exists($gradlePath)) {
            return null;
        }

        $contents = File::get($gradlePath);
        if (preg_match('/applicationId\s*=\s*"(.*?)"/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function updateAppId(string $oldAppId, string $newAppId): void
    {
        // Since AGP 7.0, namespace and applicationId are decoupled.
        // The namespace (com.nativephp.mobile) stays fixed for source code organization,
        // JNI bindings, and R class generation. Only applicationId needs to change
        // for app identity on device and Play Store.

        $gradlePath = base_path('nativephp/android/app/build.gradle.kts');
        if (File::exists($gradlePath)) {
            $contents = File::get($gradlePath);
            $contents = preg_replace('/applicationId\s*=\s*".*?"/', 'applicationId = "'.$newAppId.'"', $contents);
            File::put($gradlePath, $contents);
        }
    }

    private function updateVersionConfiguration(): void
    {
        $gradlePath = base_path('nativephp/android/app/build.gradle.kts');

        if (! File::exists($gradlePath)) {
            return;
        }

        // Auto-increment build number for package builds (only if not already updated by store check)
        if (! property_exists($this, 'buildNumberUpdatedFromStore') || ! $this->buildNumberUpdatedFromStore) {
            $this->incrementBuildNumber();
        }

        $versionName = config('nativephp.version', now()->format('Y.m.d.His'));
        $versionCode = config('nativephp.version_code', now()->timestamp);

        $contents = File::get($gradlePath);

        // Handle both REPLACEMECODE placeholder and numeric values
        $contents = preg_replace('/versionCode\s*=\s*(?:\d+|REPLACEMECODE)/', 'versionCode = '.$versionCode, $contents);

        // Handle both REPLACEME placeholder and quoted strings
        $contents = preg_replace('/versionName\s*=\s*(?:".*?"|"REPLACEME")/', 'versionName = "'.$versionName.'"', $contents);

        File::put($gradlePath, $contents);
    }

    private function updateAppDisplayName(): void
    {
        $newName = config('app.name');
        $manifestPath = base_path('nativephp/android/app/src/main/AndroidManifest.xml');

        if (! File::exists($manifestPath)) {
            return;
        }

        $this->replaceFileContentsRegex($manifestPath, '/android:label=".*?"/', 'android:label="'.$newName.'"');
    }

    private function updatePermissions(): void
    {
        $manifestPath = base_path('nativephp/android/app/src/main/AndroidManifest.xml');

        if (! File::exists($manifestPath)) {
            return;
        }

        // Core permissions - plugin permissions are handled by their nativephp.json manifests
        $permissions = [
            'push_notifications' => 'android.permission.POST_NOTIFICATIONS',
        ];

        $contents = File::get($manifestPath);

        // Handle permissions
        foreach ($permissions as $configKey => $androidPermission) {
            $isEnabled = config("nativephp.permissions.$configKey", false);

            // Handle both single permissions and arrays of permissions
            if (is_array($androidPermission) && isset($androidPermission['permission'])) {
                // Handle permission with attributes (like storage_write)
                $permission = $androidPermission['permission'];
                $attributes = $androidPermission['attributes'] ?? '';
                $hasPermission = str_contains($contents, $permission);

                if ($isEnabled && ! $hasPermission) {
                    $insertion = "    <uses-permission android:name=\"$permission\" $attributes />";
                    $contents = preg_replace('/(<manifest[^>]*>)/', "$1\n$insertion", $contents);
                } elseif (! $isEnabled && $hasPermission) {
                    $contents = preg_replace('/\s*<uses-permission\s+android:name="'.preg_quote($permission, '/').'"\s*[^>]*\/?>/', '', $contents);
                }
            } else {
                // Handle regular permissions (single or array)
                $permissionList = is_array($androidPermission) ? $androidPermission : [$androidPermission];

                foreach ($permissionList as $permission) {
                    $hasPermission = str_contains($contents, $permission);

                    if ($isEnabled && ! $hasPermission) {
                        $insertion = "    <uses-permission android:name=\"$permission\" />";
                        $contents = preg_replace('/(<manifest[^>]*>)/', "$1\n$insertion", $contents);
                    } elseif (! $isEnabled && $hasPermission) {
                        $contents = preg_replace('/\s*<uses-permission\s+android:name="'.preg_quote($permission, '/').'"\s*[^>]*\/?>/', '', $contents);
                    }
                }
            }
        }

        $normalizedContents = $this->normalizeLineEndings($contents);

        if ($this->validateXml($normalizedContents)) {
            File::put($manifestPath, $normalizedContents);
        }
    }

    private function updateDeepLinkConfiguration(): void
    {
        $scheme = config('nativephp.deeplink_scheme');
        $host = config('nativephp.deeplink_host');

        // Both are opt-in: only add filters if configured
        if (! $scheme && ! $host) {
            return;
        }

        if ($host && ! preg_match('/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/', $host)) {
            $host = null;
        }

        $manifestPath = base_path('nativephp/android/app/src/main/AndroidManifest.xml');
        if (! File::exists($manifestPath)) {
            return;
        }

        $contents = File::get($manifestPath);

        // Remove any existing deep link filters
        $contents = preg_replace(
            '/\s*<!-- NATIVEPHP-DEEPLINKS-START -->.*?<!-- NATIVEPHP-DEEPLINKS-END -->\s*/s',
            '',
            $contents
        );

        // Build the filters based on what's configured
        $filters = $this->generateDeepLinkFilters($scheme, $host);

        if ($filters) {
            // Inject filters into MainActivity
            $patterns = [
                '/(<activity[^>]*android:name="[^"]*MainActivity"[^>]*>)(.*?)(<\/activity>)/s',
                '/(<activity[^>]*android:name="[^"]*\.ui\.MainActivity"[^>]*>)(.*?)(<\/activity>)/s',
                '/(<activity[^>]*MainActivity[^>]*>)(.*?)(<\/activity>)/s',
            ];

            $matched = false;
            foreach ($patterns as $pattern) {
                $newContents = preg_replace_callback(
                    $pattern,
                    fn ($m) => $m[1].$m[2]."\n".$filters."\n        ".$m[3],
                    $contents
                );

                if ($newContents !== $contents) {
                    $contents = $newContents;
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return;
            }
        }

        $normalizedContents = $this->normalizeLineEndings($contents);

        if ($this->validateXml($normalizedContents)) {
            File::put($manifestPath, $normalizedContents);
        }
    }

    private function generateDeepLinkFilters(?string $scheme, ?string $host): string
    {
        $filters = [];

        // App Links: HTTPS with configurable host (for universal links / verified links)
        if ($host) {
            $filters[] = <<<XML
            <!-- App Links (HTTPS) -->
            <intent-filter android:autoVerify="true">
                <action android:name="android.intent.action.VIEW" />
                <category android:name="android.intent.category.DEFAULT" />
                <category android:name="android.intent.category.BROWSABLE" />
                <data android:scheme="https" android:host="{$host}" android:pathPrefix="/" />
            </intent-filter>
XML;
        }

        // Deep Links: Custom scheme (no host restriction to match iOS behavior)
        // e.g., myapp://profile/settings -> /profile/settings
        if ($scheme) {
            $filters[] = <<<XML
            <!-- Deep Links (Custom Scheme) -->
            <intent-filter>
                <action android:name="android.intent.action.VIEW" />
                <category android:name="android.intent.category.DEFAULT" />
                <category android:name="android.intent.category.BROWSABLE" />
                <data android:scheme="{$scheme}" />
            </intent-filter>
XML;
        }

        if (empty($filters)) {
            return '';
        }

        return "            <!-- NATIVEPHP-DEEPLINKS-START -->\n".implode("\n", $filters)."\n            <!-- NATIVEPHP-DEEPLINKS-END -->";
    }

    private function updateFirebaseConfiguration(): void
    {
        $source = base_path('nativephp/resources/google-services.json');

        if (! file_exists($source)) {
            $source = base_path('google-services.json');
        }

        $target = base_path('nativephp/android/app/google-services.json');

        if (File::exists($source)) {
            File::copy($source, $target);
        }
    }

    private function updateIcuConfiguration(): void
    {
        $jsonPath = base_path('nativephp.json');

        if (! file_exists($jsonPath)) {
            return;
        }

        $nativephp = json_decode(file_get_contents($jsonPath), true) ?? [];

        if (empty($nativephp['php']['icu'])) {
            return;
        }

        $appId = config('nativephp.app_id');
        $packagePath = str_replace('.', '/', $appId);
        $bridgePath = base_path("nativephp/android/app/src/main/java/{$packagePath}/bridge/PHPBridge.kt");

        if (! File::exists($bridgePath)) {
            return;
        }

        $contents = File::get($bridgePath);

        if (! str_contains($contents, 'System.loadLibrary("icudata")')) {
            $contents = str_replace(
                'System.loadLibrary("php")',
                'System.loadLibrary("icudata")'.PHP_EOL.'        System.loadLibrary("php")',
                $contents
            );
            File::put($bridgePath, $contents);
        }
    }

    private function updateLocalProperties(): void
    {
        $sdkPath = config('nativephp.android.android_sdk_path');

        if (! $sdkPath) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (preg_match('/^([A-Za-z]):(\\\\.*)|([A-Za-z]):(\/.*)/', $sdkPath, $matches)) {
                if (isset($matches[2]) && $matches[2]) {
                    // Windows path with backslashes
                    $drive = $matches[1].'\\:';
                    $rest = str_replace('\\', '\\\\', $matches[2]);
                    $sdkPath = $drive.$rest;
                } elseif (isset($matches[4]) && $matches[4]) {
                    // Windows path with forward slashes
                    $drive = $matches[3].':';
                    $sdkPath = $drive.$matches[4];
                }
            }
        } else {
            $sdkPath = str_replace('\\', '/', $sdkPath);
        }

        $path = base_path('nativephp/android/local.properties');
        File::put($path, "sdk.dir=$sdkPath".PHP_EOL);
    }

    private function runTheAndroidBuild(?string $targetDeviceId): void
    {
        $androidPath = base_path('nativephp/android');
        $gradleWrapper = PHP_OS_FAMILY === 'Windows' ? 'gradlew.bat' : './gradlew';

        if (PHP_OS_FAMILY !== 'Windows') {
            $gradlePath = $androidPath.DIRECTORY_SEPARATOR.'gradlew';
            if (! is_executable($gradlePath)) {
                chmod($gradlePath, 0755);
            }
        }

        $gradleTask = match ($this->buildType) {
            'debug' => 'assembleDebug',  // Build only, we'll install with adb -s for precise targeting
            'release' => 'assembleRelease',
            'bundle' => 'bundleRelease',
            default => throw new \Exception("Unknown build type: $this->buildType")
        };

        $verbose = $this->getOutput()->isVerbose();

        $this->components->twoColumnDetail('Build type', $this->buildType);
        $this->components->twoColumnDetail('App version', config('nativephp.version', 'Not set'));
        $this->newLine();

        $this->logToFile('--- Starting Gradle Build ---');
        $this->logToFile("Gradle wrapper: $gradleWrapper");
        $this->logToFile("Gradle task: $gradleTask");
        $this->logToFile('Verbose mode: '.($verbose ? 'enabled' : 'disabled'));

        $buildSuccessful = false;
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "cd /d \"$androidPath\" && $gradleWrapper $gradleTask";
            $this->logToFile("Windows command: $cmd");
            $exitCode = 0;
            passthru($cmd, $exitCode);

            // Also log the exit code
            $this->logToFile("Windows build exit code: $exitCode");
            $buildSuccessful = ($exitCode === 0);
        } else {
            $process = Process::path($androidPath)
                ->timeout(600);

            if (! $this->option('no-tty')) {
                $process->tty();
            }

            $result = $process->run("$gradleWrapper $gradleTask", function ($type, $output) {
                file_put_contents($this->androidLogPath, $output, FILE_APPEND);
            });

            if (! $result->successful()) {
                $this->logToFile('ERROR: Gradle build failed with exit code: '.$result->exitCode());
                error('Gradle build failed');
                note("Check the build log for details: {$this->androidLogPath}");

                return;
            }

            $buildSuccessful = $result->successful();
        }

        if (! $buildSuccessful) {
            $this->logToFile('ERROR: Build failed');
            error('Build failed.');
            note("Check the build log for details: {$this->androidLogPath}");

            return;
        }

        $this->logToFile('Gradle build completed successfully');

        if ($this->buildType === 'debug') {
            $appId = config('nativephp.app_id');
            $mainActivity = 'com.nativephp.mobile.ui.MainActivity';
            $adbCommand = PHP_OS_FAMILY === 'Windows' ? 'adb.exe' : 'adb';

            // Install APK to specific device using adb -s (more reliable than Gradle's device serial flag)
            $apkPath = base_path('nativephp/android/app/build/outputs/apk/debug/app-debug.apk');
            $installCmd = "$adbCommand -s $targetDeviceId install -r \"$apkPath\"";
            $this->logToFile("Installing APK: $installCmd");
            $installResult = Process::run($installCmd);

            if (! $installResult->successful()) {
                $this->logToFile('ERROR: APK installation failed');
                $this->logToFile($installResult->output());
                $this->logToFile($installResult->errorOutput());
                error('APK installation failed');
                note($installResult->errorOutput() ?: $installResult->output());
                note('Try freeing up space on the device or uninstalling old apps.');

                return;
            }

            $this->logToFile('APK installed on device');

            $launchCmd = "$adbCommand -s $targetDeviceId shell am start -n $appId/$mainActivity";
            $this->logToFile("Launching app: $launchCmd");
            $launchResult = Process::run($launchCmd);

            if (! $launchResult->successful()) {
                $this->logToFile('ERROR: App launch failed');
                $this->logToFile($launchResult->errorOutput());
                error('App launch failed');
                note($launchResult->errorOutput() ?: $launchResult->output());

                return;
            }

            $this->logToFile('App launched on device');
            outro('App launched!');

            // Run post-build hooks for all plugins
            $this->runAndroidPostBuildHooks();
        } else {
            $outputPath = match ($this->buildType) {
                'release' => $this->findReleaseApk(),
                'bundle' => base_path('nativephp/android/app/build/outputs/bundle/release/app-release.aab'),
                default => null,
            };

            if ($outputPath) {
                $outputPath = str_replace(['\\', "\r", "\n"], ['/', '', ''], $outputPath);
            }

            if ($outputPath && file_exists($outputPath)) {
                $fileSize = round(filesize($outputPath) / 1024 / 1024, 2);
                $this->logToFile("Build output: $outputPath");
                $this->logToFile("Output size: {$fileSize} MB");
                $this->components->twoColumnDetail('Output', $outputPath);

                if (PHP_OS_FAMILY === 'Windows') {
                    $windowsPath = str_replace('/', '\\', $outputPath);
                    $windowsPath = escapeshellarg($windowsPath);
                    exec("explorer.exe /select,$windowsPath");
                } elseif (PHP_OS_FAMILY === 'Darwin') {
                    exec("open -R \"$outputPath\"");
                } elseif (PHP_OS_FAMILY === 'Linux') {
                    if (shell_exec('which xdg-open')) {
                        exec('xdg-open "'.dirname($outputPath).'"');
                    }
                }
            } else {
                warning("Could not locate output file for build type: $this->buildType");
            }

            outro('Build complete!');

            // Run post-build hooks for all plugins
            $this->runAndroidPostBuildHooks();
        }
    }

    private function promptForAndroidTarget(): string
    {
        $devices = $this->getConnectedAndroidDevices();

        if (empty($devices)) {
            error('No connected Android devices or emulators found.');
            exit(1);
        }

        if (count($devices) === 1) {
            return array_key_first($devices);
        }

        return select('Select a device or emulator', $devices);
    }

    private function getConnectedAndroidDevices(): array
    {
        $adbCommand = PHP_OS_FAMILY === 'Windows' ? 'adb.exe' : 'adb';
        $devices = $this->parseAdbDevices($adbCommand);

        if (! empty($devices)) {
            return $devices;
        }

        note('No devices found. Attempting to launch an emulator...');

        $emulatorBinary = $this->resolveAndroidEmulatorPath();

        if (! $emulatorBinary) {
            error('Could not locate the Android emulator binary.');
            exit(1);
        }

        $listCommand = sprintf('"%s" -list-avds', $emulatorBinary);
        $listProcess = SymfonyProcess::fromShellCommandline($listCommand);
        $listProcess->run();

        if (! $listProcess->isSuccessful()) {
            error('Failed to list Android emulators.');
            exit(1);
        }

        $avds = array_filter(explode("\n", trim($listProcess->getOutput())));

        if (empty($avds)) {
            error('No AVDs found.');
            exit(1);
        }

        $selected = select(
            label: 'Select an emulator to launch',
            options: $avds
        );

        $escapedBinary = escapeshellarg($emulatorBinary);
        $escapedAvd = escapeshellarg($selected);

        if (PHP_OS_FAMILY === 'Windows') {
            $launchCommand = "start /B \"\" \"{$emulatorBinary}\" -avd \"{$selected}\"";
        } else {
            $launchCommand = "nohup $escapedBinary -avd $escapedAvd > /tmp/emulator.log 2>&1 &";
        }

        SymfonyProcess::fromShellCommandline($launchCommand)->start();

        $this->components->task('Waiting for emulator to boot', function () use ($adbCommand) {
            for ($i = 0; $i < 100; $i++) {
                $bootCompleted = $this->adbGetProp($adbCommand, 'sys.boot_completed');
                $bootAnim = $this->adbGetProp($adbCommand, 'init.svc.bootanim');

                if ($bootCompleted === '1' && $bootAnim === 'stopped') {
                    return true;
                }

                usleep(250000);
            }

            return false;
        });

        return $this->parseAdbDevices($adbCommand);
    }

    private function adbGetProp(string $adbCommand, string $property): string
    {
        $cmd = "$adbCommand shell getprop $property";

        $descriptorSpec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'], // stderr
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);

            return trim($output);
        }

        return '';
    }

    private function parseAdbDevices(string $adbCommand): array
    {
        $output = shell_exec("{$adbCommand} devices") ?: '';

        return collect(explode("\n", $output))
            ->filter(fn ($line) => Str::contains($line, "\tdevice"))
            ->mapWithKeys(fn ($line) => [explode("\t", $line)[0] => explode("\t", $line)[0]])
            ->all();
    }

    private function resolveAndroidEmulatorPath(): ?string
    {
        // 1. Allow override from config or .env
        $customPath = config('nativephp.android.emulator_path') ?? env('ANDROID_EMULATOR');
        if ($customPath && file_exists($customPath)) {
            return $customPath;
        }

        // 2. Check SDK paths from env vars
        $sdk = env('ANDROID_HOME') ?: env('ANDROID_SDK_ROOT');

        $candidates = [];

        if ($sdk) {
            $candidates[] = $sdk.DIRECTORY_SEPARATOR.'emulator'.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'emulator.exe' : 'emulator');
        }

        // 3. Fallback defaults per OS
        if (PHP_OS_FAMILY === 'Windows') {
            $username = getenv('USERNAME') ?: 'user';
            $candidates[] = "C:\\Users\\{$username}\\AppData\\Local\\Android\\Sdk\\emulator\\emulator.exe";
            $candidates[] = getenv('LOCALAPPDATA').'\\Android\\Sdk\\emulator\\emulator.exe';
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $candidates[] = getenv('HOME').'/Library/Android/sdk/emulator/emulator';
        } else { // Linux
            $candidates[] = getenv('HOME').'/Android/Sdk/emulator/emulator';
        }

        // 4. Return first found
        foreach ($candidates as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function findReleaseApk(): ?string
    {
        $apkDir = base_path('nativephp/android/app/build/outputs/apk/release');

        if (! is_dir($apkDir)) {
            $this->warn("APK directory not found: $apkDir");

            return null;
        }

        $apkFiles = glob($apkDir.'/*.apk');

        if (empty($apkFiles)) {
            $this->warn("No APK files found in: $apkDir");

            // List directory contents for debugging
            $files = scandir($apkDir);
            if ($files !== false && count($files) > 2) { // More than . and ..
                $this->info('Directory contains: '.implode(', ', array_diff($files, ['.', '..'])));
            }

            return null;
        }

        // Return the first APK found
        return $apkFiles[0];
    }

    /**
     * Validate XML content to ensure it's well-formed
     */
    private function validateXml(string $xmlContent): bool
    {
        // Suppress XML errors and handle them manually
        $oldSetting = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument;
            $result = $doc->loadXML($xmlContent);

            if (! $result) {
                $errors = libxml_get_errors();
                foreach ($errors as $error) {
                    $this->warn("XML Error: {$error->message}");
                }
                libxml_clear_errors();

                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->warn("XML Validation Exception: {$e->getMessage()}");

            return false;
        } finally {
            // Restore previous libxml error handling setting
            libxml_use_internal_errors($oldSetting);
        }
    }

    private function incrementBuildNumber(): void
    {
        // Only increment for package builds (release builds)
        // Skip for run command (development builds)
        $isPackageCommand = get_class($this) === 'Native\Mobile\Commands\PackageCommand';

        if (! $isPackageCommand) {
            return;
        }

        $currentBuildNumber = env('NATIVEPHP_APP_VERSION_CODE');

        if (! $currentBuildNumber) {
            // If not set, start with 1
            $currentBuildNumber = 1;
            // Initialize build number silently
            $this->updateEnvFile('NATIVEPHP_APP_VERSION_CODE', $currentBuildNumber);
        } else {
            // Increment the existing build number for packaging builds
            $currentBuildNumber = (int) $currentBuildNumber + 1;
            // Increment build number silently
            $this->updateEnvFile('NATIVEPHP_APP_VERSION_CODE', $currentBuildNumber);
        }
    }

    private function updateEnvFile(string $key, $value): void
    {
        $envFilePath = base_path('.env');

        if (! file_exists($envFilePath)) {
            \Laravel\Prompts\error('.env file not found');

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

    /**
     * Update Android orientation configuration based on config settings
     */
    protected function updateOrientationConfiguration(): void
    {
        $manifestPath = base_path('nativephp/android/app/src/main/AndroidManifest.xml');

        if (! File::exists($manifestPath)) {
            return;
        }

        $orientationConfig = config('nativephp.orientation.android', []);

        // Count enabled orientations
        $enabledOrientations = array_filter($orientationConfig);
        $enabledCount = count($enabledOrientations);

        // Validate that at least one orientation is enabled
        if ($enabledCount === 0) {
            throw new \Exception('All orientations are disabled for Android. At least one orientation must be enabled.');
        }

        $contents = File::get($manifestPath);

        // Determine the appropriate screenOrientation value based on enabled orientations
        $screenOrientation = null;

        if ($enabledCount === 4) {
            // All orientations enabled - use fullSensor (default behavior)
            $screenOrientation = null; // Remove any explicit orientation constraint
        } elseif (($orientationConfig['portrait'] ?? false) && ($orientationConfig['upside_down'] ?? false) && ! ($orientationConfig['landscape_left'] ?? false) && ! ($orientationConfig['landscape_right'] ?? false)) {
            // Only portrait orientations
            $screenOrientation = 'sensorPortrait';
        } elseif (! ($orientationConfig['portrait'] ?? false) && ! ($orientationConfig['upside_down'] ?? false) && (($orientationConfig['landscape_left'] ?? false) || ($orientationConfig['landscape_right'] ?? false))) {
            // Only landscape orientations
            $screenOrientation = 'sensorLandscape';
        } elseif (($orientationConfig['portrait'] ?? false) && ! ($orientationConfig['upside_down'] ?? false) && ! ($orientationConfig['landscape_left'] ?? false) && ! ($orientationConfig['landscape_right'] ?? false)) {
            // Only normal portrait
            $screenOrientation = 'portrait';
        } elseif (! ($orientationConfig['portrait'] ?? false) && ! ($orientationConfig['upside_down'] ?? false) && ($orientationConfig['landscape_left'] ?? false) && ! ($orientationConfig['landscape_right'] ?? false)) {
            // Only landscape left
            $screenOrientation = 'landscape';
        } elseif (! ($orientationConfig['portrait'] ?? false) && ! ($orientationConfig['upside_down'] ?? false) && ! ($orientationConfig['landscape_left'] ?? false) && ($orientationConfig['landscape_right'] ?? false)) {
            // Only landscape right
            $screenOrientation = 'reverseLandscape';
        } else {
            // Mixed orientations - use fullSensor to allow configured ones
            $screenOrientation = 'fullSensor';
        }

        // Update MainActivity's android:screenOrientation attribute
        if ($screenOrientation !== null) {
            // Check if screenOrientation already exists on MainActivity
            if (preg_match('/<activity[^>]*android:name="\.ui\.MainActivity"[^>]*android:screenOrientation="[^"]*"/s', $contents)) {
                // Update existing screenOrientation on MainActivity
                $contents = preg_replace(
                    '/(<activity[^>]*android:name="\.ui\.MainActivity"[^>]*android:screenOrientation=")[^"]*(")/s',
                    "$1{$screenOrientation}$2",
                    $contents
                );
            } else {
                // Add screenOrientation to MainActivity - insert before the closing >
                // Match the activity tag with MainActivity and capture up to windowSoftInputMode (last attribute)
                $contents = preg_replace(
                    '/(android:windowSoftInputMode="adjustResize")(>)/',
                    "$1\n            android:screenOrientation=\"{$screenOrientation}\"$2",
                    $contents
                );
            }
        } else {
            $contents = preg_replace('/\s*android:screenOrientation="[^"]*"/', '', $contents);
        }

        $normalizedContents = $this->normalizeLineEndings($contents);
        if ($this->validateXml($normalizedContents)) {
            File::put($manifestPath, $normalizedContents);
        }
    }

    /**
     * Compile Android plugins by copying native code and generating bridge registrations.
     *
     * @return bool True if compilation succeeded, false if it failed
     */
    protected function compileAndroidPlugins(): bool
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
            note('Add these to your .env file and try again.');

            return false;
        }

        try {
            $compiler = app(AndroidPluginCompiler::class);
            $compiler->setOutput($this->output)
                ->setAppId(config('nativephp.app_id', ''))
                ->setConfig([
                    'version' => config('nativephp.version'),
                    'version_code' => config('nativephp.version_code'),
                    'build_type' => $this->buildType ?? 'debug',
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
    protected function runAndroidPostBuildHooks(): void
    {
        $plugins = app(PluginRegistry::class);

        if ($plugins->count() === 0) {
            return;
        }

        $hookRunner = new PluginHookRunner(
            platform: 'android',
            buildPath: base_path('nativephp/android'),
            appId: config('nativephp.app_id', ''),
            config: [
                'version' => config('nativephp.version'),
                'version_code' => config('nativephp.version_code'),
                'build_type' => $this->buildType ?? 'debug',
            ],
            plugins: $plugins->all(),
            output: $this->output
        );

        $hookRunner->runPostBuildHooks();
    }
}
