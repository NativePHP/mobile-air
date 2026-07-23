<?php

namespace Native\Mobile\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Native\Mobile\Plugins\PluginRegistry;

class DebugCommand extends Command
{
    protected $signature = 'native:debug
                            {--json : Output as JSON}';

    protected $description = 'Show debug information about your NativePHP Mobile environment';

    public function __construct(protected PluginRegistry $registry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $info = $this->gatherInfo();

        if ($this->option('json')) {
            $this->line(json_encode($info, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->outputTable($info);

        return self::SUCCESS;
    }

    protected function gatherInfo(): array
    {
        $version = 'Unknown';

        try {
            $version = InstalledVersions::getPrettyVersion('nativephp/mobile') ?? 'Unknown';
        } catch (\Exception $e) {
            // Ignore
        }

        return [
            'package_version' => $version,
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'os_version' => php_uname('r'),
            'embedded_php' => $this->getEmbeddedPhpVersion(),
            'plugins' => $this->getInstalledPlugins(),
            'tools' => $this->getToolVersions(),
        ];
    }

    protected function getEmbeddedPhpVersion(): string
    {
        $lockPath = base_path('nativephp.lock');

        if (! file_exists($lockPath)) {
            return 'Not installed';
        }

        $nativephp = json_decode(file_get_contents($lockPath), true) ?? [];

        return $nativephp['php']['version'] ?? 'Not installed';
    }

    protected function getInstalledPlugins(): array
    {
        return $this->registry->allInstalled()
            ->map(fn ($plugin) => [
                'name' => $plugin->name,
                'version' => $plugin->version,
            ])
            ->values()
            ->all();
    }

    protected function getToolVersions(): array
    {
        $tools = [];

        if (PHP_OS_FAMILY === 'Darwin') {
            $tools['Xcode'] = $this->getCommandVersion('xcodebuild -version 2>/dev/null', fn ($output) => $output[0] ?? null);
        }

        $tools['Android Studio'] = $this->getAndroidStudioVersion();
        $tools['Gradle'] = $this->getGradleVersion();
        $tools['Java'] = $this->getCommandVersion('java -version 2>&1', fn ($output) => $output[0] ?? null);

        if (PHP_OS_FAMILY === 'Darwin') {
            $tools['CocoaPods'] = $this->getCommandVersion('pod --version 2>/dev/null', fn ($output) => isset($output[0]) ? 'CocoaPods '.trim($output[0]) : null);
        }

        return $tools;
    }

    protected function getCommandVersion(string $command, callable $parser): string
    {
        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return 'Not found';
        }

        return trim($parser($output) ?? '') ?: 'Not found';
    }

    protected function getAndroidStudioVersion(): string
    {
        $paths = $this->getAndroidStudioPaths();

        foreach ($paths as $pattern) {
            $matches = glob($pattern);

            if (empty($matches)) {
                continue;
            }

            // Sort descending to get the latest version directory first
            rsort($matches);

            foreach ($matches as $path) {
                if (! file_exists($path)) {
                    continue;
                }

                $json = json_decode(file_get_contents($path), true);

                if (isset($json['dataDirectoryName'])) {
                    return 'Android Studio '.$json['dataDirectoryName'];
                }

                if (isset($json['version'])) {
                    return 'Android Studio '.$json['version'];
                }
            }
        }

        return 'Not found';
    }

    protected function getAndroidStudioPaths(): array
    {
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';

        return match (PHP_OS_FAMILY) {
            'Darwin' => [
                $home.'/Applications/Android Studio.app/Contents/Resources/product-info.json',
                '/Applications/Android Studio.app/Contents/Resources/product-info.json',
            ],
            'Linux' => [
                '/opt/android-studio/product-info.json',
                '/usr/local/android-studio/product-info.json',
                $home.'/android-studio/product-info.json',
                // JetBrains Toolbox 2.x layout
                $home.'/.local/share/JetBrains/Toolbox/apps/android-studio*/product-info.json',
                // JetBrains Toolbox 1.x channel layout (glob * does not cross /, so spell out the nesting)
                $home.'/.local/share/JetBrains/Toolbox/apps/AndroidStudio/ch-*/*/product-info.json',
                // Snap
                '/snap/android-studio/current/android-studio/product-info.json',
            ],
            'Windows' => [
                'C:/Program Files/Android/Android Studio*/product-info.json',
                // JetBrains Toolbox 2.x default install location
                ($_SERVER['LOCALAPPDATA'] ?? '').'/Programs/Android Studio*/product-info.json',
            ],
            default => [],
        };
    }

    protected function getGradleVersion(): string
    {
        // Check for the Gradle wrapper in the NativePHP android project first;
        // cmd.exe can't execute the unix shell script, so use the batch wrapper on Windows
        $wrapper = PHP_OS_FAMILY === 'Windows' ? 'gradlew.bat' : 'gradlew';
        $gradlew = base_path('nativephp/android/'.$wrapper);

        if (file_exists($gradlew)) {
            // 2>&1 works on both sh and cmd.exe (2>/dev/null does not);
            // extractGradleVersion() only matches 'Gradle ' lines so stderr noise is harmless
            return $this->getCommandVersion(
                escapeshellarg($gradlew).' --version 2>&1',
                fn ($output) => $this->extractGradleVersion($output)
            );
        }

        // Fall back to a globally installed gradle
        return $this->getCommandVersion(
            'gradle --version 2>&1',
            fn ($output) => $this->extractGradleVersion($output)
        );
    }

    protected function extractGradleVersion(array $output): ?string
    {
        foreach ($output as $line) {
            if (str_starts_with($line, 'Gradle ')) {
                return $line;
            }
        }

        return null;
    }

    protected function outputTable(array $info): void
    {
        $this->components->info('NativePHP Mobile');
        $this->table([], [
            ['Package Version', $info['package_version']],
            ['PHP Version (Host)', $info['php_version']],
            ['OS', $info['os']],
            ['OS Version', $info['os_version']],
            ['Embedded PHP', $info['embedded_php']],
        ]);

        $this->newLine();
        $this->components->info('Installed Plugins');

        if (empty($info['plugins'])) {
            $this->line('  None');
        } else {
            $this->table(
                ['Package', 'Version'],
                array_map(fn ($p) => [$p['name'], $p['version']], $info['plugins'])
            );
        }

        $this->newLine();
        $this->components->info('Development Tools');
        $this->table([], array_map(
            fn ($tool, $version) => [$tool, $version],
            array_keys($info['tools']),
            array_values($info['tools'])
        ));
    }
}
