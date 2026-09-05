<?php

namespace Native\Mobile\Validation;

use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Support\PathHelper;

class NativeComponentAnalyzer
{
    protected Filesystem $files;

    protected BladeTemplateAnalyzer $bladeAnalyzer;

    public function __construct(Filesystem $files, BladeTemplateAnalyzer $bladeAnalyzer)
    {
        $this->files = $files;
        $this->bladeAnalyzer = $bladeAnalyzer;
    }

    /**
     * Analyze all NativeComponent classes in app/NativeComponents/.
     * Optionally filter to a specific component name.
     */
    public function analyze(ValidationResult $result, ?string $componentFilter = null): void
    {
        $componentPath = app_path('NativeComponents');

        if (! is_dir($componentPath)) {
            return;
        }

        $files = $this->files->allFiles($componentPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->resolveClassName($file->getPathname(), $componentPath);

            if ($className === null) {
                continue;
            }

            // Filter by component name if specified
            if ($componentFilter !== null) {
                $shortName = class_basename($className);
                if (strcasecmp($shortName, $componentFilter) !== 0) {
                    continue;
                }
            }

            $this->analyzeComponent($className, $file->getPathname(), $result);
        }
    }

    protected function analyzeComponent(string $className, string $filePath, ValidationResult $result): void
    {
        $relPath = $this->relativePath($filePath);

        // Check class can be loaded
        if (! class_exists($className)) {
            // Try to autoload by requiring the file
            try {
                require_once $filePath;
            } catch (\Throwable $e) {
                $result->error($relPath, "Could not load class: {$e->getMessage()}");

                return;
            }

            if (! class_exists($className)) {
                $result->error($relPath, "Class '{$className}' not found after loading file");

                return;
            }
        }

        // Check extends NativeComponent
        if (! is_subclass_of($className, NativeComponent::class)) {
            $result->error($relPath, 'Class does not extend NativeComponent');

            return;
        }

        // Check no property shadows NativeComponent's internal state
        $this->validateReservedProperties($className, $relPath, $result);

        // Resolve the view name from render() method
        $viewName = $this->resolveViewName($className);

        if ($viewName === null) {
            $result->warning($relPath, 'Could not determine view name from render() method');

            return;
        }

        // Check view file exists
        $viewPath = $this->viewFilePath($viewName);

        if (! $this->files->exists($viewPath)) {
            $result->error($relPath, "View 'native.{$viewName}' not found at {$this->relativePath($viewPath)}");

            return;
        }

        // Read the view content and validate it
        $viewContent = $this->files->get($viewPath);
        $viewRelPath = $this->relativePath($viewPath);

        // Validate native tags in the view
        $this->bladeAnalyzer->analyze($viewPath, $viewContent, $result);

        // Validate callbacks reference real methods on the component
        $this->validateCallbacks($className, $viewContent, $viewRelPath, $result);
    }

    /**
     * Flag any property a component declares that shadows NativeComponent's
     * internal state. The reserved set is derived from NativeComponent's own
     * non-private properties (private ones are class-scoped and can't collide),
     * so it self-maintains: only names the base class actually reserves are
     * flagged — names it has freed up (e.g. a renamed-away $running) are fine.
     *
     * Shadowing a reserved prop breaks the render loop: an incompatible type
     * fatals at class load, a compatible one silently clobbers internal state.
     */
    protected function validateReservedProperties(string $className, string $relPath, ValidationResult $result): void
    {
        try {
            $component = new \ReflectionClass($className);
            $base = new \ReflectionClass(NativeComponent::class);
        } catch (\ReflectionException $e) {
            return;
        }

        $reserved = [];
        foreach ($base->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() === NativeComponent::class && ! $prop->isPrivate()) {
                $reserved[$prop->getName()] = true;
            }
        }

        foreach ($component->getProperties() as $prop) {
            // Only properties declared on the component's own class.
            if ($prop->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            if (isset($reserved[$prop->getName()])) {
                $result->error(
                    $relPath,
                    "Property \${$prop->getName()} is reserved by NativeComponent (internal render-loop state) — rename it; shadowing it crashes the screen."
                );
            }
        }
    }

    protected function validateCallbacks(string $className, string $viewContent, string $viewRelPath, ValidationResult $result): void
    {
        $callbacks = $this->bladeAnalyzer->extractCallbacks($viewContent);

        if (empty($callbacks)) {
            return;
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $e) {
            return;
        }

        foreach ($callbacks as $callback) {
            $method = $callback['method'];
            $line = $callback['line'];

            if (! $reflection->hasMethod($method)) {
                $shortName = $reflection->getShortName();
                $result->error($viewRelPath, "Callback method '{$method}' not found on {$shortName}", $line);

                continue;
            }

            $reflMethod = $reflection->getMethod($method);
            if (! $reflMethod->isPublic()) {
                $shortName = $reflection->getShortName();
                $result->warning($viewRelPath, "Callback method '{$method}' on {$shortName} is not public", $line);
            }
        }
    }

    protected function resolveViewName(string $className): ?string
    {
        try {
            $reflection = new \ReflectionClass($className);
            $method = $reflection->getMethod('render');

            // If render() is not overridden (uses the default convention-based view),
            // infer the view name from the class name
            if ($method->getDeclaringClass()->getName() === NativeComponent::class) {
                return $className::inferViewName();
            }

            $source = $this->files->get($method->getFileName());

            // Extract the render() method body
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();
            $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
            $methodBody = implode("\n", $lines);

            // Match $this->view('name') pattern
            if (preg_match('/\$this\s*->\s*view\s*\(\s*[\'"]([^\'"]+)[\'"]/', $methodBody, $matches)) {
                return $matches[1];
            }
        } catch (\ReflectionException $e) {
            // Fall through
        }

        return null;
    }

    protected function viewFilePath(string $viewName): string
    {
        // Dots become path separators: "settings.profile" -> "settings/profile"
        $path = str_replace('.', '/', $viewName);

        return resource_path("views/native/{$path}.blade.php");
    }

    protected function resolveClassName(string $filePath, string $basePath): ?string
    {
        $relative = PathHelper::relativeTo($filePath, $basePath);
        $relative = substr($relative, 0, -4); // Remove .php
        $relative = str_replace('/', '\\', $relative);

        return 'App\\NativeComponents\\'.$relative;
    }

    protected function relativePath(string $path): string
    {
        return PathHelper::relativeTo($path, base_path());
    }
}
