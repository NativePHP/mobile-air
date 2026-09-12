<?php

namespace Native\Mobile\Plugins;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use RuntimeException;

class ProjectFileManager
{
    private string $projectPath;

    private string $nativeProjectPath;

    private string $statePath;

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
        private readonly string $platform,
    ) {
        $this->projectPath = dirname(rtrim($basePath, '/\\'));
        $this->nativeProjectPath = $basePath.'/'.$platform;
        $this->statePath = $basePath.'/.generated/project-files-'.$platform.'.json';
    }

    /**
     * Copy application-owned files declared by plugins into the generated
     * native project, and remove files managed by plugins that are no longer
     * installed or no longer declare them.
     */
    public function sync(Collection $plugins): void
    {
        $files = $this->resolveFiles($plugins);
        $previousDestinations = $this->readManagedDestinations();
        $currentDestinations = array_keys($files);

        foreach (array_diff($previousDestinations, $currentDestinations) as $destination) {
            $this->files->delete($this->nativeProjectPath.'/'.$destination);
        }

        foreach ($files as $destination => $file) {
            $target = $this->nativeProjectPath.'/'.$destination;
            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->copy($file['source'], $target);
        }

        $this->writeManagedDestinations($currentDestinations);
    }

    private function resolveFiles(Collection $plugins): array
    {
        $resolved = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->getProjectFiles($this->platform) as $declaration) {
                if (! is_array($declaration)) {
                    throw new RuntimeException(
                        "Plugin '{$plugin->name}' has an invalid {$this->platform}.project_files declaration."
                    );
                }

                $sources = $declaration['sources'] ?? [];
                $destination = $declaration['destination'] ?? null;
                $required = $declaration['required'] ?? false;

                if (is_string($sources)) {
                    $sources = [$sources];
                }

                if (! is_array($sources) || $sources === [] || ! is_string($destination) || $destination === '') {
                    throw new RuntimeException(
                        "Plugin '{$plugin->name}' must declare sources and a destination for each {$this->platform}.project_files entry."
                    );
                }

                foreach ($sources as $source) {
                    $this->assertSafeRelativePath($source, "Plugin '{$plugin->name}' project file source");
                }
                $this->assertSafeRelativePath($destination, "Plugin '{$plugin->name}' project file destination");

                $destination = $this->normalizePath($destination);

                if (isset($resolved[$destination])) {
                    throw new RuntimeException(
                        "Plugins '{$resolved[$destination]['plugin']}' and '{$plugin->name}' both manage {$this->platform} project file '{$destination}'."
                    );
                }

                $source = collect($sources)
                    ->map(fn (string $path) => $this->projectPath.'/'.$this->normalizePath($path))
                    ->first(fn (string $path) => $this->files->isFile($path));

                if ($source === null) {
                    if ($required) {
                        $sources = array_values($sources);

                        throw new RuntimeException(count($sources) === 1
                            ? "Plugin '{$plugin->name}' requires '{$sources[0]}' in your project root for {$this->platform}."
                            : "Plugin '{$plugin->name}' requires one of these project files for {$this->platform}, relative to your project root: ".implode(', ', $sources).'.'
                        );
                    }

                    continue;
                }

                $resolved[$destination] = [
                    'plugin' => $plugin->name,
                    'source' => $source,
                ];
            }
        }

        return $resolved;
    }

    private function readManagedDestinations(): array
    {
        if (! $this->files->isFile($this->statePath)) {
            return [];
        }

        $destinations = json_decode($this->files->get($this->statePath), true);

        if (! is_array($destinations)) {
            return [];
        }

        return array_values(array_filter($destinations, function ($destination) {
            if (! is_string($destination)) {
                return false;
            }

            try {
                $this->assertSafeRelativePath($destination, 'Managed project file destination');

                return true;
            } catch (RuntimeException) {
                return false;
            }
        }));
    }

    private function writeManagedDestinations(array $destinations): void
    {
        if ($destinations === []) {
            $this->files->delete($this->statePath);

            return;
        }

        $this->files->ensureDirectoryExists(dirname($this->statePath));
        $this->files->put(
            $this->statePath,
            json_encode(array_values($destinations), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    private function assertSafeRelativePath(mixed $path, string $label): void
    {
        if (! is_string($path) || $path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            throw new RuntimeException("{$label} must be a relative path.");
        }

        $segments = preg_split('/[\\\\\/]+/', $path);

        if (in_array('..', $segments, true)) {
            throw new RuntimeException("{$label} may not traverse outside the project.");
        }
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
