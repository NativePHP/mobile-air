<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Validation\BladeTemplateAnalyzer;
use Native\Mobile\Validation\EdgeComponentAnalyzer;
use Native\Mobile\Validation\NativeComponentAnalyzer;
use Native\Mobile\Validation\RouteAnalyzer;
use Native\Mobile\Validation\ValidationResult;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

class ValidateCommand extends Command
{
    protected $signature = 'native:validate
                            {--component= : Validate only a specific component}';

    protected $description = 'Validate native EDGE components for common errors';

    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        intro('Validating native components');

        $result = new ValidationResult;
        $componentFilter = $this->option('component');

        // 1. Validate NativeComponent classes + their Blade views
        $bladeAnalyzer = new BladeTemplateAnalyzer;
        $componentAnalyzer = new NativeComponentAnalyzer($this->files, $bladeAnalyzer);
        $componentAnalyzer->analyze($result, $componentFilter);

        // 2. Validate navigation chrome in all views
        if ($componentFilter === null) {
            $edgeAnalyzer = new EdgeComponentAnalyzer($this->files);
            $edgeAnalyzer->analyze($result);
        }

        // 3. Check Route::native() registrations
        $routeAnalyzer = new RouteAnalyzer($this->files);
        $this->checkRouteRegistrations($routeAnalyzer, $result, $componentFilter);

        // Output results
        return $this->renderResults($result);
    }

    protected function checkRouteRegistrations(RouteAnalyzer $routeAnalyzer, ValidationResult $result, ?string $componentFilter): void
    {
        $componentPath = app_path('NativeComponents');

        if (! is_dir($componentPath)) {
            return;
        }

        $componentMap = [];
        $files = $this->files->allFiles($componentPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($componentPath.'/', '', $file->getPathname());
            $relative = substr($relative, 0, -4);
            $relative = str_replace('/', '\\', $relative);
            $className = 'App\\NativeComponents\\'.$relative;

            if ($componentFilter !== null) {
                $shortName = class_basename($className);
                if (strcasecmp($shortName, $componentFilter) !== 0) {
                    continue;
                }
            }

            $componentMap[$file->getPathname()] = $className;
        }

        $routeAnalyzer->analyzeUnregistered($componentMap, $result);
    }

    protected function renderResults(ValidationResult $result): int
    {
        $grouped = $result->groupedByFile();

        if (empty($grouped)) {
            // Check if there were any components to validate at all
            $componentPath = app_path('NativeComponents');
            if (! is_dir($componentPath) || empty($this->files->allFiles($componentPath))) {
                $this->newLine();
                $this->components->warn('No NativeComponents found in app/NativeComponents/');
                $this->newLine();

                return self::SUCCESS;
            }

            outro('All components passed validation');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($grouped as $file => $issues) {
            $hasErrors = collect($issues)->contains('type', 'error');
            $status = $hasErrors ? '<fg=red>FAIL</>' : '<fg=yellow>WARN</>';

            $this->components->twoColumnDetail($file, $status);

            foreach ($issues as $issue) {
                $prefix = $issue['type'] === 'error' ? '<fg=red>x</>' : '<fg=yellow>!</>';
                $lineInfo = $issue['line'] ? " <fg=gray>(line {$issue['line']})</>" : '';
                $this->line("  {$prefix} {$issue['message']}{$lineInfo}");
            }
        }

        $this->newLine();

        $errorCount = $result->errorCount();
        $warningCount = $result->warningCount();

        $parts = [];
        if ($errorCount > 0) {
            $parts[] = "<fg=red>{$errorCount} error(s)</>";
        }
        if ($warningCount > 0) {
            $parts[] = "<fg=yellow>{$warningCount} warning(s)</>";
        }

        $summary = implode(', ', $parts);

        if ($errorCount > 0) {
            outro("Validation failed: {$summary}");

            return self::FAILURE;
        }

        outro("Validation passed with {$summary}");

        return self::SUCCESS;
    }
}
