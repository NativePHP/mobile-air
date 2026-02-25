<?php

namespace Native\Mobile\Traits;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\File;
use ZipArchive;

use function Laravel\Prompts\confirm;
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

        if ($this->option('with-icu')) {
            $this->includeIcu = true;
        } elseif ($this->option('without-icu')) {
            $this->includeIcu = false;
        } else {
            $this->includeIcu = confirm(
                label: 'Include ICU-enabled PHP binary for Filament/intl support?',
                default: false,
                hint: 'Adds ~30MB to your app size'
            );
        }
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

        $url = $includeIcu
            ? "https://bin.nativephp.com/android-element-8.5.3-icu.zip"
            : "https://bin.nativephp.com/android-element-8.5.3.zip";

        $zipFile = storage_path('android-temp.zip');
        $extractPath = storage_path('android-temp');

        $this->components->twoColumnDetail('ICU support', $includeIcu ? 'Enabled' : 'Disabled');

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
                $downloadFailed = true;

                return false;
            }
        });

        if ($downloadFailed) {
            error('Failed to download PHP binaries.');

            return;
        }

        $sizeMB = round(filesize($zipFile) / 1024 / 1024, 1);
        $this->components->twoColumnDetail('Download size', "{$sizeMB}MB");

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

        // Store ICU preference for run command
        $icuFlagFile = base_path('nativephp/android/.icu-enabled');
        if ($includeIcu) {
            File::put($icuFlagFile, '1');
        } elseif (File::exists($icuFlagFile)) {
            File::delete($icuFlagFile);
        }

        try {
            File::delete($zipFile);
            $this->removeDirectory($extractPath);
        } catch (\Exception $e) {
            warning('Could not remove temporary files: '.$e->getMessage());
        }
    }
}
