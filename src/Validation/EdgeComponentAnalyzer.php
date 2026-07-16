<?php

namespace Native\Mobile\Validation;

use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Edge\Components\EdgeComponent;

class EdgeComponentAnalyzer
{
    protected Filesystem $files;

    /**
     * Map of tag name (snake_case) => required props.
     * Built dynamically from EdgeComponent subclasses.
     */
    protected array $requiredPropsMap = [];

    public function __construct(Filesystem $files)
    {
        $this->files = $files;
        $this->buildRequiredPropsMap();
    }

    /**
     * Scan all blade templates for navigation chrome elements with missing required props.
     */
    public function analyze(ValidationResult $result): void
    {
        $viewsPath = resource_path('views');

        if (! is_dir($viewsPath)) {
            return;
        }

        $files = $this->files->allFiles($viewsPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = $this->files->get($file->getPathname());
            $this->analyzeTemplate($file->getPathname(), $content, $result);
        }
    }

    protected function analyzeTemplate(string $filePath, string $content, ValidationResult $result): void
    {
        $relPath = $this->relativePath($filePath);

        // Strip comments
        $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $content);
        $stripped = preg_replace('/<!--.*?-->/s', '', $stripped);

        // Match <native:tag-name ...> and <x-native-tag-name ...>
        $pattern = '/<\s*(?:native\s*:\s*|x-native-)([a-zA-Z0-9\-_]+)(\s[^>]*)?\s*\/?>/';

        if (! preg_match_all($pattern, $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as $i => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];
            $line = $this->lineNumber($stripped, $offset);

            // Skip closing tags
            if (str_starts_with(trim($fullMatch), '</')) {
                continue;
            }

            $tagName = $matches[1][$i][0];
            $attrs = $matches[2][$i][0] ?? '';

            // Normalize kebab-case to snake_case
            $elementType = str_replace('-', '_', $tagName);

            // Only check elements that have required props
            if (! isset($this->requiredPropsMap[$elementType])) {
                continue;
            }

            $requiredProps = $this->requiredPropsMap[$elementType];
            $missing = [];

            foreach ($requiredProps as $prop) {
                // Check both kebab-case and camelCase forms in the attribute string
                $kebabProp = str_replace('_', '-', $prop);
                $camelProp = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $prop))));

                $found = preg_match('/\b'.preg_quote($prop, '/').'\s*=/', $attrs)
                    || preg_match('/\b'.preg_quote($kebabProp, '/').'\s*=/', $attrs)
                    || preg_match('/\b'.preg_quote($camelProp, '/').'\s*=/', $attrs)
                    || preg_match('/:'.preg_quote($prop, '/').'\s*=/', $attrs)
                    || preg_match('/:'.preg_quote($kebabProp, '/').'\s*=/', $attrs)
                    || preg_match('/:'.preg_quote($camelProp, '/').'\s*=/', $attrs);

                if (! $found) {
                    $missing[] = $prop;
                }
            }

            if (! empty($missing)) {
                $propsStr = implode("', '", $missing);
                $result->error($relPath, "<native:{$tagName}> missing required props: '{$propsStr}'", $line);
            }
        }
    }

    /**
     * Build the required props map by reflecting on EdgeComponent subclasses.
     */
    protected function buildRequiredPropsMap(): void
    {
        $componentPath = dirname(__DIR__).'/Edge/Components';

        if (! is_dir($componentPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($componentPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($componentPath.'/', '', $file->getPathname());
            $classPath = substr($relativePath, 0, -4);
            $className = 'Native\\Mobile\\Edge\\Components\\'.str_replace('/', '\\', $classPath);

            if (! class_exists($className)) {
                continue;
            }

            if (! is_subclass_of($className, EdgeComponent::class)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);

                if ($reflection->isAbstract()) {
                    continue;
                }

                // Get the type property
                $typeProperty = $reflection->getProperty('type');
                $typeProperty->setAccessible(true);

                // Create an instance with defaults to read the type
                $instance = $reflection->newInstanceWithoutConstructor();
                $type = $typeProperty->getValue($instance);

                if (empty($type)) {
                    continue;
                }

                // Get required props via reflection
                $requiredMethod = $reflection->getMethod('requiredProps');
                $requiredMethod->setAccessible(true);
                $requiredProps = $requiredMethod->invoke($instance);

                if (! empty($requiredProps)) {
                    $this->requiredPropsMap[$type] = $requiredProps;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    protected function lineNumber(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    protected function relativePath(string $path): string
    {
        $base = base_path().'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
