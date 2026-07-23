<?php

namespace Native\Mobile\Validation;

use Illuminate\Filesystem\Filesystem;

class RouteAnalyzer
{
    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        $this->files = $files;
    }

    /**
     * Get all component classes registered via Route::native() by scanning route files.
     *
     * @return array<string> Fully-qualified class names
     */
    public function registeredComponents(): array
    {
        $registered = [];

        $routeFiles = $this->getRouteFiles();

        foreach ($routeFiles as $routeFile) {
            if (! $this->files->exists($routeFile)) {
                continue;
            }

            $content = $this->files->get($routeFile);
            $registered = array_merge($registered, $this->extractNativeRoutes($content));
        }

        return array_unique($registered);
    }

    /**
     * Check if a component class is registered via Route::native().
     */
    public function isRegistered(string $className): bool
    {
        return in_array($className, $this->registeredComponents());
    }

    /**
     * Add warnings for components not registered via Route::native().
     */
    public function analyzeUnregistered(array $componentClasses, ValidationResult $result): void
    {
        $registered = $this->registeredComponents();

        foreach ($componentClasses as $filePath => $className) {
            if (! in_array($className, $registered)) {
                $relPath = $this->relativePath($filePath);
                $shortName = class_basename($className);
                $result->warning($relPath, "Component {$shortName} not registered via Route::native()");
            }
        }
    }

    /**
     * Extract class names from Route::native() calls in a file.
     *
     * @return array<string>
     */
    protected function extractNativeRoutes(string $content): array
    {
        $classes = [];

        // Match Route::native('/uri', ClassName::class)
        // Also match with use statements resolving the class
        $pattern = '/Route\s*::\s*native\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*([^)]+)\s*\)/';

        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $classRef) {
                $classRef = trim($classRef);

                // Handle ::class syntax: "SomeClass::class" or "\App\NativeComponents\Counter::class"
                if (str_ends_with($classRef, '::class')) {
                    $className = substr($classRef, 0, -7); // Remove ::class
                    $className = ltrim($className, '\\');

                    // Try to resolve short class names via use statements in the same file
                    if (! str_contains($className, '\\')) {
                        $resolved = $this->resolveUseStatement($content, $className);
                        if ($resolved !== null) {
                            $className = $resolved;
                        }
                    }

                    $classes[] = $className;
                }
            }
        }

        return $classes;
    }

    /**
     * Resolve a short class name to its fully-qualified name via use statements.
     */
    protected function resolveUseStatement(string $content, string $shortName): ?string
    {
        $pattern = '/use\s+([\w\\\\]+\\\\'.preg_quote($shortName, '/').')\s*;/';

        if (preg_match($pattern, $content, $matches)) {
            return ltrim($matches[1], '\\');
        }

        return null;
    }

    protected function getRouteFiles(): array
    {
        $files = [];

        // Standard Laravel route files
        $routesPath = base_path('routes');
        if (is_dir($routesPath)) {
            foreach ($this->files->allFiles($routesPath) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    protected function relativePath(string $path): string
    {
        // Normalize to forward slashes so the base-path prefix strips on
        // Windows too (base_path() returns backslashes there).
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path()).'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
