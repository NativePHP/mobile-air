<?php

namespace Native\Mobile\Traits;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\File;
use ZipArchive;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

trait InstallsAndroid
{
    use PlatformFileOperations;

    protected ?bool $includeIcu = null;

    public function promptAndroidOptions(): void
    {
        // Skip if --skip-php is passed
        if ($this->option('skip-php') && ! $this->forcing) {
            return;
        }

        $this->includeIcu = (bool) $this->option('with-icu');
    }

    public function setupAndroid(): void
    {
        $this->createAndroidStudioProject();

        // Skip PHP installation if --skip-php is passed, unless --force/--fresh is also passed
        $shouldSkipPhp = $this->option('skip-php') && ! $this->forcing;

        if ($shouldSkipPhp) {
            $this->components->warn('Skipping PHP binary installation (--skip-php)');
        } else {
            $this->installPHPAndroid();
        }
    }

    private function createAndroidStudioProject(): void
    {
        $androidPath = base_path('nativephp/android');

        if ($this->forcing && File::exists($androidPath)) {
            $this->removeDirectory($androidPath);
        }

        File::ensureDirectoryExists($androidPath);

        $source = base_path('vendor/nativephp/mobile/resources/androidstudio');

        $this->components->task('Creating Android project', fn () => $this->platformOptimizedCopy($source, $androidPath));
    }

    private function installPHPAndroid(): void
    {
        $includeIcu = $this->includeIcu ?? false;
        $phpVersion = $this->phpVersion;
        $versions = $this->versionsManifest;

        if (! $versions || ! isset($versions['versions'][$phpVersion])) {
            error("PHP {$phpVersion} binaries not available");

            return;
        }

        $androidFiles = $versions['versions'][$phpVersion]['android'] ?? [];

        $url = null;
        foreach ($androidFiles as $fileUrl) {
            $isIcu = str_contains($fileUrl, '-icu.');
            if ($includeIcu && $isIcu) {
                $url = $fileUrl;
                break;
            } elseif (! $includeIcu && ! $isIcu) {
                $url = $fileUrl;
                break;
            }
        }

        if (! $url) {
            $variant = $includeIcu ? 'ICU' : 'non-ICU';
            error("No {$variant} Android binary found for PHP {$phpVersion}");

            return;
        }

        $cacheDir = base_path('nativephp/binaries');
        File::ensureDirectoryExists($cacheDir);

        $zipFilename = basename(parse_url($url, PHP_URL_PATH));
        $zipFile = $cacheDir.DIRECTORY_SEPARATOR.$zipFilename;
        $extractPath = storage_path('android-temp');

        $this->components->twoColumnDetail('PHP version', $phpVersion.'.x');
        $this->components->twoColumnDetail('ICU support', $includeIcu ? 'Enabled' : 'Disabled');

        if (file_exists($zipFile)) {
            $sizeMB = round(filesize($zipFile) / 1024 / 1024, 1);
            $this->components->twoColumnDetail('Cached binary', "{$zipFilename} ({$sizeMB}MB)");
        } else {
            $client = new Client;
            $downloadFailed = false;

            $this->components->task('Downloading Android PHP binaries', function () use ($client, $url, $zipFile, &$downloadFailed) {
                try {
                    $client->request('GET', $url, [
                        'sink' => $zipFile,
                        'connect_timeout' => 60,
                        'timeout' => 600,
                    ]);

                    return true;
                } catch (RequestException) {
                    // Remove any partial/error response written to disk
                    if (file_exists($zipFile)) {
                        unlink($zipFile);
                    }
                    $downloadFailed = true;

                    return false;
                }
            });

            if ($downloadFailed) {
                error("Failed to download PHP binaries from: $url");

                return;
            }

            // Verify the downloaded file is actually a ZIP
            $zip = new ZipArchive;
            if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
                error('Downloaded file is not a valid ZIP archive. The URL may be incorrect.');
                unlink($zipFile);

                return;
            }
            $zip->close();

            $sizeMB = round(filesize($zipFile) / 1024 / 1024, 1);
            $this->components->twoColumnDetail('Download size', "{$sizeMB}MB");
        }

        File::ensureDirectoryExists($extractPath);

        if (PHP_OS_FAMILY === 'Windows') {
            $sevenZip = config('nativephp.android.7zip-location');

            if (! file_exists($sevenZip)) {
                error("7-Zip not found at: $sevenZip");
                note('Install 7-Zip or set NATIVEPHP_7ZIP_LOCATION environment variable.');

                return;
            }

            $extractFailed = false;

            $this->components->task('Extracting PHP binaries', function () use ($sevenZip, $zipFile, $extractPath, &$extractFailed) {
                $cmd = "\"$sevenZip\" x \"$zipFile\" \"-o$extractPath\" -y";
                exec($cmd, $output, $code);

                if ($code !== 0) {
                    $extractFailed = true;

                    return false;
                }

                return true;
            });

            if ($extractFailed) {
                error('7-Zip extraction failed.');

                return;
            }
        } else {
            $zip = new ZipArchive;

            if ($zip->open($zipFile) !== true) {
                error('Failed to open downloaded ZIP file.');

                return;
            }

            $this->components->task('Extracting PHP binaries', function () use ($zip, $extractPath) {
                $zip->extractTo($extractPath);
                $zip->close();
            });
        }

        $destination = base_path('nativephp/android/app/src/main');
        File::ensureDirectoryExists($destination);

        $this->components->task('Installing Android libraries', fn () => $this->platformOptimizedCopy($extractPath, $destination));

        try {
            $this->removeDirectory($extractPath);
        } catch (\Exception $e) {
            warning('Could not remove temporary files: '.$e->getMessage());
        }
    }
}
