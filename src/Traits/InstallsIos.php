<?php

namespace Native\Mobile\Traits;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use ZipArchive;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

trait InstallsIos
{
    public string $iosPath;

    protected ?bool $includeIcuIos = null;

    public function promptIosOptions(): void
    {
        if ($this->option('skip-php') && ! $this->forcing) {
            return;
        }

        $this->includeIcuIos = (bool) $this->option('with-icu');
    }

    public function setupIos(): void
    {
        $this->iosPath = base_path('nativephp/ios');
        $this->createXcodeProject();
        $this->configureDeveloperTeam();

        // Skip PHP installation if --skip-php is passed, unless --force/--fresh is also passed
        $shouldSkipPhp = $this->option('skip-php') && ! $this->forcing;

        if ($shouldSkipPhp) {
            $this->components->warn('Skipping PHP binary installation (--skip-php)');
        } else {
            $this->installPHPIos();
        }
    }

    private function createXcodeProject(): void
    {
        if (! is_dir($this->iosPath)) {
            mkdir($this->iosPath, 0755, true);
        }

        $this->components->task('Creating Xcode project', fn () => File::copyDirectory(
            base_path('vendor/nativephp/mobile/resources/xcode'),
            $this->iosPath
        ));
    }

    private function installPHPIos(): void
    {
        $includeIcu = $this->includeIcuIos ?? false;
        $phpVersion = $this->phpVersion;
        $versions = $this->versionsManifest;

        if (! $versions || ! isset($versions['versions'][$phpVersion])) {
            error("PHP {$phpVersion} binaries not available");

            return;
        }

        $iosFiles = $versions['versions'][$phpVersion]['ios'] ?? [];

        $url = null;
        foreach ($iosFiles as $fileUrl) {
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
            error("No {$variant} iOS binary found for PHP {$phpVersion}");

            return;
        }

        $this->components->twoColumnDetail('PHP version', $phpVersion.'.x');
        $this->components->twoColumnDetail('ICU support', $includeIcu ? 'Enabled' : 'Disabled');

        $cacheDir = base_path('nativephp/binaries');
        File::ensureDirectoryExists($cacheDir);

        $zipFilename = basename(parse_url($url, PHP_URL_PATH));
        $zipFile = $cacheDir.DIRECTORY_SEPARATOR.$zipFilename;
        $extractPath = storage_path('ios-temp');

        if (file_exists($zipFile)) {
            $sizeMB = round(filesize($zipFile) / 1024 / 1024, 1);
            $this->components->twoColumnDetail('Cached binary', "{$zipFilename} ({$sizeMB}MB)");
        } else {
            $client = new Client;
            $downloadFailed = false;

            $this->components->task('Downloading iOS PHP binaries', function () use ($client, $url, $zipFile, &$downloadFailed) {
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

        $zip = new ZipArchive;

        if ($zip->open($zipFile) !== true) {
            error('Failed to open downloaded ZIP file.');

            return;
        }

        $this->components->task('Extracting PHP binaries', function () use ($zip, $extractPath) {
            $zip->extractTo($extractPath);
            $zip->close();
        });

        File::ensureDirectoryExists($this->iosPath);

        $this->components->task('Installing iOS libraries', function () use ($extractPath) {
            File::copyDirectory($extractPath.'/Libraries', $this->iosPath.'/Libraries');
            File::copyDirectory($extractPath.'/Include', $this->iosPath.'/Include');
        });

        // Re-copy our custom Bridge files (PHP.c, PHP.h) which contain the persistent
        // runtime implementation — the binary ZIP ships older/template versions
        $bridgeSrc = __DIR__.'/../../resources/xcode/Include/Bridge';
        $bridgeDst = $this->iosPath.'/Include/Bridge';
        if (is_dir($bridgeSrc)) {
            File::copyDirectory($bridgeSrc, $bridgeDst);
        }

        try {
            File::deleteDirectory($extractPath);
        } catch (\Exception $e) {
            warning('Could not remove temporary files: '.$e->getMessage());
        }
    }

    private function configureDeveloperTeam(): void
    {
        $teamId = $this->getTeamId();

        if (! $teamId) {
            warning('No development team found. Code signing may fail.');
            note('Make sure you have a valid Apple Developer account and certificates installed.');

            return;
        }

        $this->updateDevelopmentTeam($teamId);
        $this->components->twoColumnDetail('Development team', $teamId);
    }

    private function getTeamId(): ?string
    {
        // 1. Check if explicitly set via environment variable
        if ($teamId = config('nativephp.development_team')) {
            return $teamId;
        }

        // 2. Try to detect from code signing identities
        $result = Process::run('security find-identity -v -p codesigning');

        if (! $result->successful()) {
            return null;
        }

        $output = $result->output();

        // Look for Apple Development or Apple Distribution certificates
        // Format: "Apple Development: Name (TEAMID)" or "Apple Development: email (MEMBERID)"
        preg_match_all('/Apple (?:Development|Distribution): .+? \(([A-Z0-9]+)\)/', $output, $matches);

        if (empty($matches[1])) {
            return null;
        }

        // Get the first team ID found
        $teamId = $matches[1][0];

        // If it's a member ID (10 chars), try to get the team ID from Apple Developer portal
        if (strlen($teamId) === 10) {
            $actualTeamId = $this->getTeamIdFromMemberId($teamId);

            return $actualTeamId ?: $teamId;
        }

        return $teamId;
    }

    private function getTeamIdFromMemberId(string $memberId): ?string
    {
        // Try to get team ID from Xcode's DerivedData or previous builds
        $result = Process::run("defaults read com.apple.dt.Xcode DVTDeveloperAccountManager 2>/dev/null || echo ''");

        if ($result->successful() && ! empty($result->output())) {
            // Parse the output to find team IDs associated with this member
            preg_match_all('/teamID\s*=\s*([A-Z0-9]+)/', $result->output(), $matches);
            if (! empty($matches[1])) {
                return $matches[1][0];
            }
        }

        return null;
    }

    private function updateDevelopmentTeam(string $teamId): void
    {
        $projectPath = $this->iosPath.'/NativePHP.xcodeproj/project.pbxproj';

        if (! file_exists($projectPath)) {
            error("Xcode project file not found at: $projectPath");

            return;
        }

        $content = file_get_contents($projectPath);

        $content = preg_replace(
            '/DEVELOPMENT_TEAM = [A-Z0-9"]+;/',
            "DEVELOPMENT_TEAM = $teamId;",
            $content
        );

        file_put_contents($projectPath, $content);
    }
}
