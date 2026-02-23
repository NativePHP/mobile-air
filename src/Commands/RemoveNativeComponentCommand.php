<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;

class RemoveNativeComponentCommand extends Command
{
    protected $signature = 'native:rm
                            {name? : The name of the component to remove (e.g. Counter, Settings/Profile)}';

    protected $description = 'Remove a NativeComponent class';

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

        if (! confirm("Delete {$relativePath}?", default: false)) {
            $this->components->info('Cancelled.');

            return self::SUCCESS;
        }

        $this->files->delete($filePath);

        // Clean up empty parent directories
        $directory = dirname($filePath);
        $baseDir = app_path('NativeComponents');
        while ($directory !== $baseDir && $this->files->isDirectory($directory) && empty($this->files->files($directory)) && empty($this->files->directories($directory))) {
            $this->files->deleteDirectory($directory);
            $directory = dirname($directory);
        }

        outro("Removed {$relativePath}");

        return self::SUCCESS;
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
            ->mapWithKeys(function ($file) use ($baseDir) {
                $relative = str_replace($baseDir.'/', '', $file->getPathname());
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
