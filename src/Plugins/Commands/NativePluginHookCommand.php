<?php

namespace Native\Mobile\Plugins\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\InputOption;
use ZipArchive;

/**
 * Base command class for NativePHP plugin lifecycle hooks.
 *
 * Extend this class to create hook commands that run during the plugin build process.
 * Hook commands receive context about the build environment and have access to
 * helper methods for common operations like copying assets and downloading files.
 *
 * @example
 * class CopyAssetsCommand extends NativePluginHookCommand {
 *     protected $signature = 'nativephp:my-plugin:copy-assets';
 *
 *     public function handle() {
 *         if ($this->platform() === 'android') {
 *             $this->copyToAndroidAssets('resources/models/model.tflite', 'models/model.tflite');
 *         }
 *     }
 * }
 */
abstract class NativePluginHookCommand extends Command
{
    /**
     * Common options for all hook commands
     */
    protected function configure(): void
    {
        $this->addOption('platform', null, InputOption::VALUE_REQUIRED, 'Target platform (ios/android)');
        $this->addOption('build-path', null, InputOption::VALUE_REQUIRED, 'Path to native project');
        $this->addOption('plugin-path', null, InputOption::VALUE_REQUIRED, 'Path to plugin package');
        $this->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'Application ID');
        $this->addOption('config', null, InputOption::VALUE_REQUIRED, 'Build configuration (JSON)');
        $this->addOption('plugins', null, InputOption::VALUE_REQUIRED, 'All registered plugins (JSON)');
    }

    // =========================================================================
    // Context Accessors
    // =========================================================================

    /**
     * Get the target platform (ios or android)
     */
    protected function platform(): string
    {
        return $this->option('platform') ?? 'unknown';
    }

    /**
     * Check if building for iOS
     */
    protected function isIos(): bool
    {
        return $this->platform() === 'ios';
    }

    /**
     * Check if building for Android
     */
    protected function isAndroid(): bool
    {
        return $this->platform() === 'android';
    }

    /**
     * Get the path to the native project being built
     */
    protected function buildPath(): string
    {
        return $this->option('build-path') ?? '';
    }

    /**
     * Get the path to this plugin's package
     */
    protected function pluginPath(): string
    {
        return $this->option('plugin-path') ?? '';
    }

    /**
     * Get the application ID (e.g., com.example.app)
     */
    protected function appId(): string
    {
        return $this->option('app-id') ?? '';
    }

    /**
     * Get the build configuration array
     */
    protected function config(): array
    {
        $config = $this->option('config');

        return $config ? json_decode($config, true) : [];
    }

    /**
     * Get all registered plugins
     */
    protected function allPlugins(): array
    {
        $plugins = $this->option('plugins');

        return $plugins ? json_decode($plugins, true) : [];
    }

    // =========================================================================
    // File Operations - Platform Specific
    // =========================================================================

    /**
     * Copy a file to Android assets directory
     *
     * @param  string  $source  Relative path from plugin's resources/ directory
     * @param  string  $dest  Relative path within assets/ (e.g., 'models/model.tflite')
     */
    protected function copyToAndroidAssets(string $source, string $dest): bool
    {
        $sourcePath = $this->pluginPath().'/resources/'.$source;
        $destPath = $this->buildPath().'/app/src/main/assets/'.$dest;

        return $this->copyFile($sourcePath, $destPath);
    }

    /**
     * Copy a file to Android res directory
     *
     * @param  string  $source  Relative path from plugin's resources/ directory
     * @param  string  $dest  Relative path within res/ (e.g., 'raw/sound.mp3')
     */
    protected function copyToAndroidRes(string $source, string $dest): bool
    {
        $sourcePath = $this->pluginPath().'/resources/'.$source;
        $destPath = $this->buildPath().'/app/src/main/res/'.$dest;

        return $this->copyFile($sourcePath, $destPath);
    }

    /**
     * Copy a file to iOS bundle Resources
     *
     * @param  string  $source  Relative path from plugin's resources/ directory
     * @param  string  $dest  Relative path within Resources/ (e.g., 'models/model.tflite')
     */
    protected function copyToIosBundle(string $source, string $dest): bool
    {
        $sourcePath = $this->pluginPath().'/resources/'.$source;
        $destPath = $this->buildPath().'/NativePHP/Resources/'.$dest;

        return $this->copyFile($sourcePath, $destPath);
    }

    /**
     * Copy a file to iOS Assets.xcassets
     *
     * @param  string  $source  Relative path from plugin's resources/ directory
     * @param  string  $dest  Relative path within Assets.xcassets/
     */
    protected function copyToIosAssets(string $source, string $dest): bool
    {
        $sourcePath = $this->pluginPath().'/resources/'.$source;
        $destPath = $this->buildPath().'/NativePHP/Assets.xcassets/'.$dest;

        return $this->copyFile($sourcePath, $destPath);
    }

    // =========================================================================
    // File Operations - Generic
    // =========================================================================

    /**
     * Copy a file, creating destination directory if needed
     */
    protected function copyFile(string $source, string $dest): bool
    {
        if (! file_exists($source)) {
            $this->error("Source file not found: {$source}");

            return false;
        }

        $this->ensureDirectory(dirname($dest));

        if (copy($source, $dest)) {
            $this->info('Copied: '.basename($source).' -> '.$dest);

            return true;
        }

        $this->error("Failed to copy: {$source} -> {$dest}");

        return false;
    }

    /**
     * Copy a directory recursively
     */
    protected function copyDirectory(string $source, string $dest): bool
    {
        if (! is_dir($source)) {
            $this->error("Source directory not found: {$source}");

            return false;
        }

        $this->ensureDirectory($dest);

        $files = new Filesystem;
        $files->copyDirectory($source, $dest);

        $this->info("Copied directory: {$source} -> {$dest}");

        return true;
    }

    /**
     * Ensure a directory exists, creating it if needed
     */
    protected function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        if (mkdir($path, 0755, true)) {
            return true;
        }

        $this->error("Failed to create directory: {$path}");

        return false;
    }

    /**
     * Delete a file or directory
     */
    protected function delete(string $path): bool
    {
        $files = new Filesystem;

        if (is_dir($path)) {
            $files->deleteDirectory($path);
        } elseif (file_exists($path)) {
            $files->delete($path);
        }

        return true;
    }

    // =========================================================================
    // Download Helpers
    // =========================================================================

    /**
     * Download a file from a URL
     */
    protected function downloadFile(string $url, string $destination): bool
    {
        $this->info("Downloading: {$url}");

        try {
            $response = Http::timeout(300)->get($url);

            if (! $response->successful()) {
                $this->error("Download failed: HTTP {$response->status()}");

                return false;
            }

            $this->ensureDirectory(dirname($destination));
            file_put_contents($destination, $response->body());

            $this->info("Downloaded to: {$destination}");

            return true;
        } catch (\Exception $e) {
            $this->error("Download failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Download a file only if it doesn't already exist
     */
    protected function downloadIfMissing(string $url, string $destination): bool
    {
        if (file_exists($destination)) {
            $this->info("File already exists: {$destination}");

            return true;
        }

        return $this->downloadFile($url, $destination);
    }

    /**
     * Extract a ZIP file
     */
    protected function unzip(string $zipPath, string $extractTo): bool
    {
        if (! file_exists($zipPath)) {
            $this->error("ZIP file not found: {$zipPath}");

            return false;
        }

        $this->ensureDirectory($extractTo);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error("Failed to open ZIP: {$zipPath}");

            return false;
        }

        $zip->extractTo($extractTo);
        $zip->close();

        $this->info("Extracted to: {$extractTo}");

        return true;
    }

    /**
     * Download and extract a ZIP file
     */
    protected function downloadAndUnzip(string $url, string $extractTo): bool
    {
        $tempZip = sys_get_temp_dir().'/'.uniqid('plugin_').'.zip';

        if (! $this->downloadFile($url, $tempZip)) {
            return false;
        }

        $result = $this->unzip($tempZip, $extractTo);

        // Clean up temp file
        @unlink($tempZip);

        return $result;
    }

    // =========================================================================
    // Console Helpers
    // =========================================================================

    /**
     * Display a progress bar while executing steps
     *
     * @param  int  $steps  Number of steps
     * @param  callable  $callback  Function that receives the progress bar
     */
    protected function progress(int $steps, callable $callback): void
    {
        $bar = $this->output->createProgressBar($steps);
        $bar->start();

        $callback($bar);

        $bar->finish();
        $this->newLine();
    }

    /**
     * Display a table
     */
    protected function displayTable(array $headers, array $rows): void
    {
        $this->table($headers, $rows);
    }

    /**
     * Ask for confirmation
     */
    protected function shouldContinue(string $question): bool
    {
        return $this->confirm($question, true);
    }
}
