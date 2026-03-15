<?php

namespace Native\Mobile\Support;

use Illuminate\Filesystem\Filesystem;

class Stub
{
    protected static ?string $stubPath = null;

    protected string $content;

    public function __construct(string $stubName)
    {
        $path = self::getStubPath().'/'.$stubName;

        if (! file_exists($path)) {
            throw new \RuntimeException("Stub not found: {$stubName}");
        }

        $this->content = file_get_contents($path);
    }

    /**
     * Create a new stub instance
     */
    public static function make(string $stubName): self
    {
        return new self($stubName);
    }

    /**
     * Replace a placeholder with a value
     */
    public function replace(string $placeholder, string $value): self
    {
        $this->content = str_replace("{{ {$placeholder} }}", $value, $this->content);

        return $this;
    }

    /**
     * Replace multiple placeholders
     */
    public function replaceAll(array $replacements): self
    {
        foreach ($replacements as $placeholder => $value) {
            $this->replace($placeholder, $value);
        }

        return $this;
    }

    /**
     * Get the rendered content
     */
    public function render(): string
    {
        return $this->content;
    }

    /**
     * Save the rendered stub to a file
     */
    public function saveTo(string $path, ?Filesystem $files = null): void
    {
        $files = $files ?? new Filesystem;
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->content);
    }

    /**
     * Get the stub directory path
     */
    public static function getStubPath(): string
    {
        if (self::$stubPath !== null) {
            return self::$stubPath;
        }

        return dirname(__DIR__, 2).'/resources/stubs';
    }

    /**
     * Set a custom stub path (useful for testing)
     */
    public static function setStubPath(?string $path): void
    {
        self::$stubPath = $path;
    }

    /**
     * Magic method to get the rendered content
     */
    public function __toString(): string
    {
        return $this->render();
    }
}
