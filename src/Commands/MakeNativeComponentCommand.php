<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

class MakeNativeComponentCommand extends Command
{
    protected $signature = 'native:make
                            {name : The name of the component (e.g. Counter, Settings/Profile)}
                            {--force : Overwrite if the component already exists}';

    protected $description = 'Create a new NativeComponent class and Blade view';

    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        intro('Creating NativeComponent');

        $name = str_replace('/', '\\', $this->argument('name'));
        $parts = explode('\\', $name);
        $className = array_pop($parts);
        $subNamespace = implode('\\', $parts);

        $namespace = 'App\\NativeComponents';
        if ($subNamespace) {
            $namespace .= '\\'.$subNamespace;
        }

        // ── PHP class ───────────────────────────────────

        $classDirectory = app_path('NativeComponents');
        if ($subNamespace) {
            $classDirectory .= '/'.str_replace('\\', '/', $subNamespace);
        }

        $classPath = $classDirectory.'/'.$className.'.php';

        if ($this->files->exists($classPath) && ! $this->option('force')) {
            $this->components->error("{$className} already exists at {$classPath}");

            if (! confirm('Overwrite it?', default: false)) {
                return self::FAILURE;
            }
        }

        // ── Blade view ──────────────────────────────────

        $viewName = Str::kebab($className);
        $viewSubDir = '';
        if ($subNamespace) {
            $viewSubDir = collect(explode('\\', $subNamespace))
                ->map(fn ($p) => Str::kebab($p))
                ->implode('/');
        }

        $viewDirectory = resource_path('views/native');
        if ($viewSubDir) {
            $viewDirectory .= '/'.$viewSubDir;
        }

        $viewPath = $viewDirectory.'/'.$viewName.'.blade.php';

        $viewDotName = $viewSubDir
            ? str_replace('/', '.', $viewSubDir).'.'.$viewName
            : $viewName;

        if ($this->files->exists($viewPath) && ! $this->option('force')) {
            $this->components->error("View already exists at {$viewPath}");

            if (! confirm('Overwrite it?', default: false)) {
                return self::FAILURE;
            }
        }

        // ── Write files ─────────────────────────────────

        $this->files->ensureDirectoryExists($classDirectory);
        $this->files->ensureDirectoryExists($viewDirectory);

        $classStub = $this->files->get(__DIR__.'/../../resources/stubs/NativeComponent.php.stub');
        $classContent = str_replace(
            ['{{ NAMESPACE }}', '{{ CLASS }}', '{{ VIEW_NAME }}'],
            [$namespace, $className, $viewDotName],
            $classStub
        );
        $this->files->put($classPath, $classContent);

        $viewStub = $this->files->get(__DIR__.'/../../resources/stubs/NativeView.blade.php.stub');
        $viewContent = str_replace('{{ CLASS }}', $className, $viewStub);
        $this->files->put($viewPath, $viewContent);

        // ── Output ──────────────────────────────────────

        // Strip the base path by length and normalize separators so the
        // relative path displays cleanly on Windows (where app_path() and
        // resource_path() join with backslashes) as well as macOS/Linux.
        $relative = fn (string $path): string => ltrim(str_replace('\\', '/', substr($path, strlen(base_path()))), '/');
        $relativeClassPath = $relative($classPath);
        $relativeViewPath = $relative($viewPath);

        outro("Created {$className}");

        $this->components->twoColumnDetail('Class', $relativeClassPath);
        $this->components->twoColumnDetail('View', $relativeViewPath);

        $this->newLine();
        $this->line('  Register it in your routes:');
        $this->line("  <comment>Route::native('/".Str::kebab($className)."', \\{$namespace}\\{$className}::class);</comment>");

        return self::SUCCESS;
    }
}
