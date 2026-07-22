<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;

class RemoveNativeComponentCommand extends Command
{
    protected $signature = 'native:rm
                            {name? : The name of the component to remove (e.g. Counter, Settings/Profile)}';

    protected $description = 'Remove a NativeComponent class and its Blade view';

    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        intro('Remove NativeComponent');

        $name = $this->argument('name');

        if (! $name) {
            $name = $this->selectComponent();
            if (! $name) {
                return self::FAILURE;
            }
        }

        $name = str_replace('/', '\\', $name);
        $relativePath = 'app/NativeComponents/'.str_replace('\\', '/', $name).'.php';
        $filePath = base_path($relativePath);

        if (! $this->files->exists($filePath)) {
            $this->components->error("Component not found: {$relativePath}");

            return self::FAILURE;
        }

        // ── Mirror MakeNativeComponentCommand's view path resolution ──
        $parts = explode('\\', $name);
        $className = array_pop($parts);
        $subNamespace = implode('\\', $parts);

        $viewName = Str::kebab($className);
        $viewSubDir = $subNamespace
            ? collect(explode('\\', $subNamespace))
                ->map(fn ($p) => Str::kebab($p))
                ->implode('/')
            : '';

        $viewDirectory = resource_path('views/native');
        if ($viewSubDir) {
            $viewDirectory .= '/'.$viewSubDir;
        }

        $viewPath = $viewDirectory.'/'.$viewName.'.blade.php';
        $viewExists = $this->files->exists($viewPath);
        // Normalize separators before stripping base_path() — on Windows base_path()
        // is backslashed while $viewPath is mixed, so a naive replace never matches
        $relativeViewPath = ltrim(str_replace(str_replace('\\', '/', base_path()), '', str_replace('\\', '/', $viewPath)), '/');

        $targets = $relativePath.($viewExists ? " and {$relativeViewPath}" : '');
        if (! confirm("Delete {$targets}?", default: false)) {
            $this->components->info('Cancelled.');

            return self::SUCCESS;
        }

        $this->files->delete($filePath);
        if ($viewExists) {
            $this->files->delete($viewPath);
        }

        // Clean up empty parent directories
        $this->cleanupEmptyDirs(dirname($filePath), app_path('NativeComponents'));
        if ($viewExists) {
            $this->cleanupEmptyDirs(dirname($viewPath), resource_path('views/native'));
        }

        outro("Removed {$targets}");

        return self::SUCCESS;
    }

    protected function cleanupEmptyDirs(string $directory, string $baseDir): void
    {
        // Normalize separators so the strict comparison against $baseDir holds on
        // Windows, where dirname() preserves mixed slashes and would otherwise let
        // the loop delete the base directory itself
        $normalize = fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/');
        $directory = $normalize($directory);
        $baseDir = $normalize($baseDir);

        while ($directory !== $baseDir && $this->files->isDirectory($directory) && empty($this->files->files($directory)) && empty($this->files->directories($directory))) {
            $this->files->deleteDirectory($directory);
            $directory = $normalize(dirname($directory));
        }
    }

    protected function selectComponent(): ?string
    {
        $baseDir = app_path('NativeComponents');

        if (! is_dir($baseDir)) {
            $this->components->error('No NativeComponents directory found.');

            return null;
        }

        $files = collect($this->files->allFiles($baseDir))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->mapWithKeys(function ($file) {
                // getRelativePathname() avoids separator-mismatch issues on Windows;
                // normalize to forward slashes so handle()'s path building works
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $name = substr($relative, 0, -4); // strip .php

                return [$name => $name];
            })
            ->toArray();

        if (empty($files)) {
            $this->components->error('No NativeComponents found.');

            return null;
        }

        return select(
            label: 'Which component?',
            options: $files,
        );
    }
}
