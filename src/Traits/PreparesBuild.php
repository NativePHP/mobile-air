<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

trait PreparesBuild
{
    use CleansEnvFile, InstallsAndroidSplashScreen, InstallsAppIcon, PlatformFileOperations;

    /**
     * Validate required environment variables for building
     */
    protected function validateBuildEnvironment(): bool
    {
        $this->logToFile('Validating build environment...');

        $required = [
            'NATIVEPHP_APP_ID' => config('nativephp.app_id'),
            'NATIVEPHP_APP_VERSION' => config('nativephp.version'),
        ];

        foreach ($required as $key => $value) {
            $this->logToFile("  $key: ".($value ?: '(not set)'));
        }

        $missing = collect($required)
            ->filter(fn ($value, $key) => empty($value))
            ->keys()
            ->all();

        if (! empty($missing)) {
            $this->logToFile('ERROR: Missing required environment variables: '.implode(', ', $missing));
            \Laravel\Prompts\error('Required environment variables are missing: '.implode(', ', $missing));
            \Laravel\Prompts\note(<<<'NOTE'
                Please set these in your .env file or config/nativephp.php.

                See: https://nativephp.com/docs/mobile/2/getting-started/introduction
                NOTE);

            return false;
        }

        $this->logToFile('Build environment validation passed');

        return true;
    }

    /**
     * Prepare Android build environment
     */
    protected function prepareAndroidBuild(bool $cleanCache = true, bool $excludeDevDependencies = true): void
    {
        $this->logToFile('--- Preparing Android Build ---');

        if ($cleanCache) {
            $this->cleanGradleCache();
        } else {
            $this->logToFile('Skipping Gradle cache clean (debug build)');
        }

        $this->updateAndroidConfiguration();
        $this->installAndroidIcon();
        $this->installAndroidSplashScreen();
        $this->prepareLaravelBundle($excludeDevDependencies);
        $this->logToFile('--- Android Build Preparation Complete ---');
    }

    /**
     * Clean Gradle cache
     */
    protected function cleanGradleCache(): void
    {
        $this->logToFile('Cleaning Gradle cache...');
        $androidPath = base_path('nativephp/android');
        $gradleDir = $androidPath.DIRECTORY_SEPARATOR.'.gradle';
        $buildDir = $androidPath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'build';

        $this->components->task('Cleaning Gradle cache', function () use ($gradleDir, $buildDir) {
            if (is_dir($gradleDir)) {
                $this->logToFile("  Removing: $gradleDir");
                $this->removeDirectory($gradleDir);
            }

            if (is_dir($buildDir)) {
                $this->logToFile("  Removing: $buildDir");
                $this->removeDirectory($buildDir);
            }
        });

        $this->logToFile('Gradle cache cleaned');
    }

    /**
     * Update Android configuration
     */
    protected function updateAndroidConfiguration(): void
    {
        $this->logToFile('Updating Android configuration...');

        $this->components->task('Updating Android configuration', function () {
            $appId = config('nativephp.app_id');
            $oldAppId = $this->detectCurrentAppId();

            $this->logToFile("  App ID: $appId");
            if ($oldAppId && $oldAppId !== $appId) {
                $this->logToFile("  Updating App ID from: $oldAppId");
                $this->updateAppId($oldAppId, $appId);
            }

            // Always replace the placeholder package in all Kotlin files (for plugins)
            $this->replacePackagePlaceholder($appId);

            $this->logToFile('  Updating ICU configuration...');
            $this->updateIcuConfiguration();

            $this->logToFile('  Updating version configuration...');
            $this->logToFile('    Version: '.config('nativephp.version', 'not set'));
            $this->logToFile('    Version Code: '.config('nativephp.version_code', 'not set'));
            $this->updateVersionConfiguration();

            $this->logToFile('  Updating app display name: '.config('app.name'));
            $this->updateAppDisplayName();

            $this->logToFile('  Updating permissions...');
            $this->updatePermissions();

            $this->logToFile('  Updating orientation configuration...');
            $this->updateOrientationConfiguration();

            $scheme = config('nativephp.deeplink_scheme');
            $host = config('nativephp.deeplink_host');
            $this->logToFile('  Updating deep link configuration...');
            if ($scheme) {
                $this->logToFile("    Scheme: $scheme");
            }
            if ($host) {
                $this->logToFile("    Host: $host");
            }
            $this->updateDeepLinkConfiguration();

            $this->logToFile('  Updating Firebase configuration...');
            $this->updateFirebaseConfiguration();

            $this->logToFile('  Updating build configuration...');
            $buildConfig = config('nativephp.android.build', []);
            $this->logToFile('    Minify: '.($buildConfig['minify_enabled'] ?? false ? 'enabled' : 'disabled'));
            $this->logToFile('    Shrink Resources: '.($buildConfig['shrink_resources'] ?? false ? 'enabled' : 'disabled'));
            $this->logToFile('    Debug Symbols: '.($buildConfig['debug_symbols'] ?? 'FULL'));
            $this->updateBuildConfiguration();

            $this->logToFile('  Updating status bar style: '.config('nativephp.android.status_bar_style', 'auto'));
            $this->updateStatusBarStyleConfiguration();

            $this->logToFile('  Updating local properties...');
            $sdkPath = config('nativephp.android.android_sdk_path');
            if ($sdkPath) {
                $this->logToFile("    SDK Path: $sdkPath");
            }
            $this->updateLocalProperties();
        });

        $this->logToFile('Android configuration updated');
    }

    /**
     * Update status bar style configuration in MainActivity.kt
     */
    protected function updateStatusBarStyleConfiguration(): void
    {
        // The namespace is fixed at com.nativephp.mobile, so the path is always the same
        $mainActivityPath = base_path('nativephp'.DIRECTORY_SEPARATOR.'android'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'main'.DIRECTORY_SEPARATOR.'java'.DIRECTORY_SEPARATOR.'com'.DIRECTORY_SEPARATOR.'nativephp'.DIRECTORY_SEPARATOR.'mobile'.DIRECTORY_SEPARATOR.'ui'.DIRECTORY_SEPARATOR.'MainActivity.kt');

        if (! File::exists($mainActivityPath)) {
            return;
        }

        $content = File::get($mainActivityPath);
        $statusBarStyle = config('nativephp.android.status_bar_style', 'auto');

        if (str_contains($content, 'REPLACE_STATUS_BAR_STYLE')) {
            $content = str_replace('REPLACE_STATUS_BAR_STYLE', $statusBarStyle, $content);
        } else {
            $content = preg_replace(
                '/val\s+statusBarStyle\s*=\s*"[^"]*"/',
                'val statusBarStyle = "'.$statusBarStyle.'"',
                $content
            );
        }

        File::put($mainActivityPath, $content);
    }

    /**
     * Prepare Laravel bundle
     */
    protected function prepareLaravelBundle(bool $excludeDevDependencies = true): void
    {
        $this->logToFile('Preparing Laravel bundle...');

        $source = realpath(base_path());
        $destinationZip = base_path('nativephp/android/app/src/main/assets/laravel_bundle.zip');

        $this->logToFile("  Source: $source");
        $this->logToFile("  Destination: $destinationZip");

        $tempDir = PHP_OS_FAMILY === 'Windows'
            ? 'C:\\temp\\'.time()
            : base_path('nativephp/android/laravel');

        $this->logToFile("  Temp directory: $tempDir");

        if (is_dir($tempDir)) {
            $this->logToFile('  Removing existing temp directory...');
            $this->removeDirectory($tempDir);
        }
        File::ensureDirectoryExists($tempDir);

        try {
            if (file_exists($destinationZip)) {
                $this->logToFile('  Removing existing bundle zip...');
                unlink($destinationZip);
            }

            $excludedDirs = match (PHP_OS_FAMILY) {
                'Windows' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp', 'vendor/nativephp/mobile/resources']),
                'Linux' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp/ios', 'nativephp/android']),
                'Darwin' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp/ios', 'nativephp/android']),
                default => config('nativephp.cleanup_exclude_files'),
            };

            $this->logToFile('  Excluded directories: '.implode(', ', $excludedDirs));

            $srcDir = base_path('vendor/nativephp/mobile/bootstrap/android');

            $this->logToFile('  Copying Laravel source...');
            $this->components->task('Copying Laravel source', fn () => $this->platformOptimizedCopy($source, $tempDir, $excludedDirs));

            $composerArgs = $excludeDevDependencies ? '--no-dev --no-interaction' : '--no-interaction';

            $this->logToFile('  Installing Composer dependencies'.($excludeDevDependencies ? ' (--no-dev)' : '').'...');
            $this->components->task('Installing Composer dependencies', function () use ($tempDir, $composerArgs) {
                $result = Process::path($tempDir)
                    ->timeout(300)
                    ->run("composer install {$composerArgs}");

                $this->logToFile($result->output());
                if ($result->errorOutput()) {
                    $this->logToFile($result->errorOutput());
                }

                return $result->successful();
            });

            $this->logToFile('  Optimizing autoloader...');
            $this->components->task('Optimizing autoloader', function () use ($tempDir) {
                $result = Process::path($tempDir)
                    ->timeout(60)
                    ->run('composer dump-autoload --optimize --classmap-authoritative');

                $this->logToFile($result->output());
                if ($result->errorOutput()) {
                    $this->logToFile($result->errorOutput());
                }

                return $result->successful();
            });

            $version = config('nativephp.version', now()->format('Ymd-His'));
            $this->logToFile("  Writing version file: $version");
            file_put_contents($tempDir.DIRECTORY_SEPARATOR.'.version', $version.PHP_EOL);

            if (file_exists($source.DIRECTORY_SEPARATOR.'.env')) {
                $this->logToFile('  Copying and cleaning .env file...');
                $envPath = $tempDir.DIRECTORY_SEPARATOR.'.env';
                copy($source.DIRECTORY_SEPARATOR.'.env', $envPath);
                $this->cleanEnvFile($envPath);
            }

            $artisanPhp = "{$srcDir}/artisan.php";
            if (file_exists($artisanPhp)) {
                $this->logToFile('  Copying artisan.php bootstrap...');
                File::copy($artisanPhp, "{$tempDir}/artisan.php");
            }

            $this->logToFile('  Creating bundle archive...');
            $this->components->task('Creating bundle archive', fn () => $this->createZipBundle($tempDir, $destinationZip, $excludedDirs));

            if (! file_exists($destinationZip) || filesize($destinationZip) <= 1000) {
                $this->logToFile('ERROR: Failed to create valid zip file');
                \Laravel\Prompts\error('Failed to create valid zip file.');
                exit(1);
            }

            // Write bundle_meta.json alongside the ZIP for fast boot-time metadata reads
            $assetsDir = dirname($destinationZip);
            $bifrostAppId = null;
            if (file_exists($source.DIRECTORY_SEPARATOR.'.env')) {
                $envContent = file_get_contents($source.DIRECTORY_SEPARATOR.'.env');
                if (preg_match('/BIFROST_APP_ID=(.+)/', $envContent, $matches)) {
                    $bifrostAppId = trim($matches[1]);
                }
            }
            $bundleMeta = json_encode([
                'version' => $version,
                'bifrost_app_id' => $bifrostAppId,
                'runtime_mode' => config('nativephp.runtime.mode', 'persistent'),
            ], JSON_PRETTY_PRINT);
            file_put_contents($assetsDir.DIRECTORY_SEPARATOR.'bundle_meta.json', $bundleMeta);
            $runtimeMode = config('nativephp.runtime.mode', 'persistent');
            $this->logToFile("  Written bundle_meta.json: version=$version, bifrost=".($bifrostAppId ?? 'null').", runtime_mode=$runtimeMode");

            $sizeMB = round(filesize($destinationZip) / 1024 / 1024, 2);
            $this->logToFile("  Bundle size: {$sizeMB} MB");
            $this->components->twoColumnDetail('Bundle size', "{$sizeMB} MB");
        } finally {
            $this->logToFile('  Cleaning up temp directory...');
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Create ZIP bundle with cross-platform support
     */
    protected function createZipBundle(string $source, string $destination, array $excludedDirs = []): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $sevenZip = config('nativephp.android.7zip-location');
            if (! file_exists($sevenZip)) {
                \Laravel\Prompts\error("7-Zip not found at: $sevenZip");
                exit(1);
            }

            $cmd = "\"$sevenZip\" a -tzip \"$destination\" \"$source\\*\" -xr!node_modules";
            exec($cmd, $output, $code);

            if ($code !== 0) {
                \Laravel\Prompts\error("7-Zip failed with exit code $code");
                exit(1);
            }

            return;
        }

        $zip = new \ZipArchive;
        $result = $zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            \Laravel\Prompts\error("Cannot create zip file at: $destination");
            exit(1);
        }

        $this->addDirectoryToZip($zip, $source, '', $excludedDirs);

        $requiredDirs = [
            'bootstrap/cache',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
        ];

        foreach ($requiredDirs as $dir) {
            if (! $zip->statName($dir)) {
                $zip->addEmptyDir($dir);
            }
        }

        $closeResult = $zip->close();
        if (! $closeResult) {
            \Laravel\Prompts\error('Failed to close ZIP file properly');
            exit(1);
        }
    }

    /**
     * Add directory contents to ZIP archive
     */
    protected function addDirectoryToZip(\ZipArchive $zip, string $source, string $prefix = '', array $excludedDirs = []): void
    {
        $source = rtrim(str_replace('\\', '/', $source), '/').'/';

        $files = iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        ));

        foreach ($files as $file) {
            $filePath = str_replace('\\', '/', $file->getRealPath());
            $relativePath = ltrim(str_replace('\\', '/', substr($filePath, strlen($source))), '/');

            // Check against configured exclusions first
            $shouldExclude = false;
            foreach ($excludedDirs as $excludedDir) {
                // Handle wildcard patterns (e.g., "public/fonts/*")
                if (str_contains($excludedDir, '*')) {
                    $pattern = str_replace('*', '.*', preg_quote($excludedDir, '/'));
                    if (preg_match('/^'.$pattern.'/', $relativePath)) {
                        $shouldExclude = true;
                        break;
                    }
                } else {
                    // Exact directory matching
                    if (Str::startsWith($relativePath, rtrim($excludedDir, '/').'/') || $relativePath === rtrim($excludedDir, '/')) {
                        $shouldExclude = true;
                        break;
                    }
                }
            }

            // Always exclude these directories
            if ($shouldExclude ||
                Str::startsWith($relativePath, 'vendor/nativephp/mobile/resources') ||
                Str::startsWith($relativePath, 'vendor/nativephp/mobile/vendor') ||
                Str::startsWith($relativePath, '.idea') ||
                Str::startsWith($relativePath, 'output') ||
                Str::startsWith($relativePath, 'storage/framework/views/') ||
                Str::startsWith($relativePath, 'storage/framework/cache/') ||
                Str::startsWith($relativePath, 'storage/framework/sessions/') ||
                Str::startsWith($relativePath, 'storage/app/native-build') ||
                Str::startsWith($relativePath, 'bootstrap/cache/') ||
                Str::startsWith($relativePath, 'nativephp') ||
                Str::startsWith($relativePath, 'public/storage') ||
                Str::endsWith($relativePath, '.jks') ||
                Str::endsWith($relativePath, '.zip')) {
                continue;
            }

            try {
                if ($file->isDir()) {
                    $zip->addEmptyDir($prefix.$relativePath);
                } else {
                    $zip->addFile($filePath, $prefix.$relativePath);
                }
            } catch (\Throwable) {
                // Skip files that can't be added
            }
        }
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        $bytes /= (1 << (10 * $power));

        return round($bytes, $precision).' '.$units[$power];
    }

    /**
     * Execute Gradle build with optional signing configuration
     */
    protected function executeGradleBuild(string $gradleTask, ?array $signingConfig = null): bool
    {
        $androidPath = base_path('nativephp/android');
        $gradleWrapper = PHP_OS_FAMILY === 'Windows' ? 'gradlew.bat' : './gradlew';

        $this->newLine();

        if (PHP_OS_FAMILY !== 'Windows') {
            $gradlePath = $androidPath.DIRECTORY_SEPARATOR.'gradlew';
            if (! is_executable($gradlePath)) {
                chmod($gradlePath, 0755);
            }
        }

        // Apply signing configuration if provided
        if ($signingConfig) {
            $this->applySigningConfiguration($signingConfig);
        }

        $this->components->twoColumnDetail('Running Gradle', $gradleTask);

        // Also pass signing properties as system properties for extra reliability
        $extraArgs = '';
        if ($signingConfig) {
            $extraArgs = sprintf(
                ' -PMYAPP_UPLOAD_STORE_FILE="%s" -PMYAPP_UPLOAD_KEY_ALIAS="%s" -PMYAPP_UPLOAD_STORE_PASSWORD="%s" -PMYAPP_UPLOAD_KEY_PASSWORD="%s"',
                str_replace('\\', '/', $signingConfig['keystore']),
                $signingConfig['keyAlias'],
                $signingConfig['keystorePassword'],
                $signingConfig['keyPassword']
            );
        }

        $buildSuccessful = false;

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "cd /d \"$androidPath\" && $gradleWrapper $gradleTask$extraArgs";
            $exitCode = 0;
            passthru($cmd, $exitCode);
            $buildSuccessful = ($exitCode === 0);
        } else {
            $process = Process::path($androidPath)
                ->timeout(600);

            if (! $this->option('no-tty')) {
                $process->tty();
            }

            $result = $process->run("$gradleWrapper $gradleTask$extraArgs");

            if (! $result->successful()) {
                \Laravel\Prompts\error('Gradle build failed');

                return false;
            }

            $buildSuccessful = $result->successful();
        }

        // Verify signing if this was a signed build
        if ($buildSuccessful && $signingConfig) {
            $this->verifyBuildSigning($gradleTask, $signingConfig);
        }

        // Copy mapping files to output directory if configured
        if ($buildSuccessful) {
            $this->copyMappingFilesToOutput($gradleTask);
        }

        return $buildSuccessful;
    }

    /**
     * Verify that the build output is properly signed
     */
    protected function verifyBuildSigning(string $gradleTask, array $signingConfig): void
    {
        // Verify build signing silently

        // Determine output file path based on build task
        $outputPath = null;

        if (str_contains($gradleTask, 'bundleRelease')) {
            $outputPath = base_path('nativephp/android/app/build/outputs/bundle/release/app-release.aab');
        } elseif (str_contains($gradleTask, 'assembleRelease')) {
            // Find the release APK
            $apkDir = base_path('nativephp/android/app/build/outputs/apk/release');
            if (is_dir($apkDir)) {
                $apkFiles = glob($apkDir.'/*.apk');
                $outputPath = ! empty($apkFiles) ? $apkFiles[0] : null;
            }
        }

        if (! $outputPath || ! file_exists($outputPath)) {
            return;
        }

        // Use jarsigner to verify the signature
        $verifyCmd = sprintf('jarsigner -verify -verbose %s 2>&1', escapeshellarg($outputPath));
        $verifyOutput = shell_exec($verifyCmd);

        if (str_contains($verifyOutput, 'jar is unsigned')) {
            \Laravel\Prompts\warning('Build output is unsigned - this will be rejected by Google Play Console');
        }
    }

    /**
     * Copy mapping files to output directory if configured and --output flag is used
     */
    protected function copyMappingFilesToOutput(string $gradleTask): void
    {
        // Check if mapping files are configured to be generated
        $buildConfig = config('nativephp.android.build', []);
        $generateMappingFiles = $buildConfig['generate_mapping_files'] ?? false;

        if (! $generateMappingFiles) {
            return;
        }

        // Check if --output option was specified
        $outputOption = $this->option('output');
        if (! $outputOption) {
            return;
        }

        // Check if mapping files actually exist
        $mappingDir = base_path('nativephp/android/app/build/outputs/mapping/release');
        $mappingFile = $mappingDir.DIRECTORY_SEPARATOR.'mapping.txt';

        if (! File::exists($mappingFile)) {
            return;
        }

        // Ensure output directory exists
        $outputDir = base_path($outputOption);
        File::ensureDirectoryExists($outputDir);

        try {
            $outputMappingFile = $outputDir.DIRECTORY_SEPARATOR.'mapping.txt';
            File::copy($mappingFile, $outputMappingFile);

            $additionalFiles = ['configuration.txt', 'resources.txt', 'seeds.txt', 'usage.txt'];

            foreach ($additionalFiles as $fileName) {
                $sourceFile = $mappingDir.DIRECTORY_SEPARATOR.$fileName;
                $destFile = $outputDir.DIRECTORY_SEPARATOR.$fileName;

                if (File::exists($sourceFile)) {
                    File::copy($sourceFile, $destFile);
                }
            }

            $this->components->twoColumnDetail('Mapping files', $outputOption);

        } catch (\Exception $e) {
            // Silently fail for mapping files
        }
    }

    /**
     * Check if command can be executed
     */
    protected function canRunCommand(string $command): bool
    {
        try {
            $process = SymfonyProcess::fromShellCommandline($command);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Apply signing configuration to gradle build
     */
    protected function applySigningConfiguration(array $signingConfig): void
    {
        $buildGradlePath = base_path('nativephp/android/app/build.gradle.kts');

        if (! File::exists($buildGradlePath)) {
            throw new \Exception("build.gradle.kts not found at: $buildGradlePath");
        }

        // Apply signing configuration

        // Validate keystore file exists and is readable
        $keystorePath = $signingConfig['keystore'];
        if (! File::exists($keystorePath)) {
            throw new \Exception("Keystore file not found: $keystorePath");
        }

        if (! is_readable($keystorePath)) {
            throw new \Exception("Keystore file is not readable: $keystorePath");
        }

        // Test keystore accessibility (without revealing the password in logs)
        try {
            // Quick validation using keytool to ensure the keystore is valid
            // Use platform-specific stderr suppression
            $stderrRedirect = PHP_OS_FAMILY === 'Windows' ? '2>nul' : '2>/dev/null';
            $validateCmd = sprintf(
                'keytool -list -keystore %s -alias %s -storepass %s %s',
                escapeshellarg($keystorePath),
                escapeshellarg($signingConfig['keyAlias']),
                escapeshellarg($signingConfig['keystorePassword']),
                $stderrRedirect
            );

            $result = shell_exec($validateCmd);
            if (empty($result)) {
                $this->warn('Could not validate keystore/alias combination. Proceeding anyway...');
            }
        } catch (\Exception $e) {
            $this->warn('Keystore validation failed: '.$e->getMessage());
        }

        // Create gradle.properties with signing config
        $gradlePropertiesPath = base_path('nativephp/android/gradle.properties');
        $gradleProperties = File::exists($gradlePropertiesPath) ? File::get($gradlePropertiesPath) : '';

        // Remove existing signing properties
        $gradleProperties = preg_replace('/.*MYAPP_UPLOAD_STORE_FILE.*\n?/', '', $gradleProperties);
        $gradleProperties = preg_replace('/.*MYAPP_UPLOAD_KEY_ALIAS.*\n?/', '', $gradleProperties);
        $gradleProperties = preg_replace('/.*MYAPP_UPLOAD_STORE_PASSWORD.*\n?/', '', $gradleProperties);
        $gradleProperties = preg_replace('/.*MYAPP_UPLOAD_KEY_PASSWORD.*\n?/', '', $gradleProperties);

        // Add new signing properties with proper escaping for Windows paths
        $gradleProperties .= "\n# Signing configuration\n";

        // Ensure proper path format for Gradle (use forward slashes, even on Windows)
        $gradleKeystorePath = str_replace('\\', '/', $keystorePath);

        $gradleProperties .= 'MYAPP_UPLOAD_STORE_FILE='.$gradleKeystorePath."\n";
        $gradleProperties .= 'MYAPP_UPLOAD_KEY_ALIAS='.$signingConfig['keyAlias']."\n";
        $gradleProperties .= 'MYAPP_UPLOAD_STORE_PASSWORD='.$signingConfig['keystorePassword']."\n";
        $gradleProperties .= 'MYAPP_UPLOAD_KEY_PASSWORD='.$signingConfig['keyPassword']."\n";

        File::put($gradlePropertiesPath, $gradleProperties);

        $this->updateGradleBuildFile($buildGradlePath);
    }

    /**
     * Update the build.gradle.kts file to ensure proper signing configuration
     */
    protected function updateGradleBuildFile(string $buildGradlePath): void
    {
        $gradleContent = File::get($buildGradlePath);

        if (str_contains($gradleContent, 'signingConfigs')) {
            if (str_contains($gradleContent, 'tasks.named("bundleRelease")')) {
                $gradleContent = preg_replace('/\/\/ Ensure bundle task uses signing configuration.*?tasks\.named\("bundleRelease"\)\s*\{[^}]*\}\s*/s', '// Bundle task verification will be handled by the signing configuration itself'."\n", $gradleContent);
                File::put($buildGradlePath, $gradleContent);
            }

            return;
        }

        // Add signing configuration before buildTypes
        $signingConfigBlock = '
    signingConfigs {
        create("release") {
            val keystoreFile = project.findProperty("MYAPP_UPLOAD_STORE_FILE") as String?
            val keyAlias = project.findProperty("MYAPP_UPLOAD_KEY_ALIAS") as String?
            val storePassword = project.findProperty("MYAPP_UPLOAD_STORE_PASSWORD") as String?
            val keyPassword = project.findProperty("MYAPP_UPLOAD_KEY_PASSWORD") as String?
            
            println("NativePHP: Checking signing configuration...")
            println("NativePHP: Keystore file: $keystoreFile")
            println("NativePHP: Key alias: $keyAlias")
            println("NativePHP: Store password provided: ${!storePassword.isNullOrEmpty()}")
            println("NativePHP: Key password provided: ${!keyPassword.isNullOrEmpty()}")
            
            if (!keystoreFile.isNullOrEmpty() && 
                !keyAlias.isNullOrEmpty() && 
                !storePassword.isNullOrEmpty() && 
                !keyPassword.isNullOrEmpty()) {
                
                val keystoreFileObj = file(keystoreFile)
                if (keystoreFileObj.exists()) {
                    println("NativePHP: ✅ Applying signing configuration")
                    storeFile = keystoreFileObj
                    this.keyAlias = keyAlias
                    this.storePassword = storePassword
                    this.keyPassword = keyPassword
                } else {
                    println("NativePHP: ❌ Keystore file not found: $keystoreFile")
                }
            } else {
                println("NativePHP: ⚠️  Signing configuration incomplete, building unsigned")
            }
        }
    }
';

        // Inject signing configuration before buildTypes
        $gradleContent = str_replace('    buildTypes {', $signingConfigBlock."\n    buildTypes {", $gradleContent);

        // Update the release build type to use signing config
        $releasePattern = '/(release\s*\{)(.*?)(isMinifyEnabled\s*=\s*false)/s';
        $releaseReplacement = '$1
            // Apply signing configuration
            val releaseSigningConfig = signingConfigs.getByName("release")
            if (releaseSigningConfig.storeFile != null) {
                println("NativePHP: ✅ Signing configuration applied to release build")
                signingConfig = releaseSigningConfig
            } else {
                println("NativePHP: ⚠️  No signing configuration - building unsigned")
            }
            
            $3';

        $gradleContent = preg_replace($releasePattern, $releaseReplacement, $gradleContent);

        File::put($buildGradlePath, $gradleContent);
    }

    /**
     * Update Android build configuration based on config settings
     */
    protected function updateBuildConfiguration(): void
    {
        $buildConfig = config('nativephp.android.build', []);

        $buildGradlePath = base_path('nativephp/android/app/build.gradle.kts');
        if (! File::exists($buildGradlePath)) {
            return;
        }

        $gradleContent = File::get($buildGradlePath);

        $minifyEnabled = $buildConfig['minify_enabled'] ?? false ? 'true' : 'false';
        $shrinkResources = $buildConfig['shrink_resources'] ?? false ? 'true' : 'false';
        $debugSymbols = $buildConfig['debug_symbols'] ?? 'FULL';

        // SDK version configuration
        $compileSdk = (int) config('nativephp.android.compile_sdk', 36);
        $minSdk = (int) config('nativephp.android.min_sdk', 33);
        $targetSdk = (int) config('nativephp.android.target_sdk', 36);

        $gradleContent = str_replace('REPLACE_COMPILE_SDK', (string) $compileSdk, $gradleContent);
        $gradleContent = str_replace('REPLACE_MIN_SDK', (string) $minSdk, $gradleContent);
        $gradleContent = str_replace('REPLACE_TARGET_SDK', (string) $targetSdk, $gradleContent);

        $gradleContent = preg_replace('/compileSdk\s*=\s*\d+/', "compileSdk = $compileSdk", $gradleContent);
        $gradleContent = preg_replace('/minSdk\s*=\s*\d+/', "minSdk = $minSdk", $gradleContent);
        $gradleContent = preg_replace('/targetSdk\s*=\s*\d+/', "targetSdk = $targetSdk", $gradleContent);

        $gradleContent = str_replace('REPLACE_MINIFY_ENABLED', $minifyEnabled, $gradleContent);
        $gradleContent = str_replace('REPLACE_SHRINK_RESOURCES', $shrinkResources, $gradleContent);
        $gradleContent = str_replace('REPLACE_DEBUG_SYMBOLS', $debugSymbols, $gradleContent);

        $gradleContent = preg_replace('/isMinifyEnabled\s*=\s*(true|false)/', "isMinifyEnabled = $minifyEnabled", $gradleContent);
        $gradleContent = preg_replace('/isShrinkResources\s*=\s*(true|false)/', "isShrinkResources = $shrinkResources", $gradleContent);
        $gradleContent = preg_replace('/debugSymbolLevel\s*=\s*"[^"]*"/', "debugSymbolLevel = \"$debugSymbols\"", $gradleContent);

        File::put($buildGradlePath, $gradleContent);

        $proguardPath = base_path('nativephp/android/app/proguard-rules.pro');
        if (! File::exists($proguardPath)) {
            return;
        }

        $proguardContent = File::get($proguardPath);

        $keepLineNumbers = $buildConfig['keep_line_numbers'] ?? false ? '-keepattributes SourceFile,LineNumberTable' : '';
        $keepSourceFile = $buildConfig['keep_source_file'] ?? false ? '-renamesourcefileattribute SourceFile' : '';
        $customRules = $buildConfig['custom_proguard_rules'] ?? [];

        $customRulesString = '';
        if (is_array($customRules) && ! empty($customRules)) {
            $customRulesString = implode("\n", $customRules);
        } elseif (is_string($customRules)) {
            $customRulesString = $customRules;
        }

        $obfuscationEnabled = $buildConfig['obfuscate'] ?? false;
        $obfuscationControl = $obfuscationEnabled ? '' : '-dontobfuscate';

        $proguardReplacements = [
            'REPLACE_KEEP_LINE_NUMBERS' => $keepLineNumbers,
            'REPLACE_KEEP_SOURCE_FILE' => $keepSourceFile,
            'REPLACE_OBFUSCATION_CONTROL' => $obfuscationControl,
            'REPLACE_CUSTOM_PROGUARD_RULES' => $customRulesString,
        ];

        foreach ($proguardReplacements as $placeholder => $value) {
            $proguardContent = str_replace($placeholder, $value, $proguardContent);
        }

        File::put($proguardPath, $proguardContent);
    }

    /**
     * Replace the placeholder package name in all Kotlin files
     * This is used to update plugin files that use com.example.androidphp as a placeholder
     */
    protected function replacePackagePlaceholder(string $newAppId): void
    {
        $placeholder = 'com.example.androidphp';

        // Skip if the app ID is the placeholder (nothing to replace)
        if ($newAppId === $placeholder) {
            return;
        }

        $javaSrcRoot = base_path('nativephp/android/app/src/main/java');

        if (! is_dir($javaSrcRoot)) {
            return;
        }

        // Find and update all Kotlin files that contain the placeholder
        collect(File::allFiles($javaSrcRoot))
            ->filter(fn ($file) => $file->getExtension() === 'kt')
            ->each(function ($file) use ($placeholder, $newAppId) {
                $contents = $file->getContents();
                if (str_contains($contents, $placeholder)) {
                    $contents = str_replace($placeholder, $newAppId, $contents);
                    File::put($file->getPathname(), $contents);
                }
            });
    }

    // These methods need to be implemented in the concrete class or imported from other traits
    abstract protected function detectCurrentAppId(): ?string;

    abstract protected function updateAppId(string $oldAppId, string $newAppId): void;

    abstract protected function updateLocalProperties(): void;

    abstract protected function updateVersionConfiguration(): void;

    abstract protected function updateAppDisplayName(): void;

    abstract protected function updateDeepLinkConfiguration(): void;

    abstract protected function updatePermissions(): void;

    abstract protected function updateIcuConfiguration(): void;

    abstract protected function updateFirebaseConfiguration(): void;

    abstract protected function removeDirectory(string $path): void;

    abstract protected function platformOptimizedCopy(string $source, string $destination, array $excludedDirs): void;
}
