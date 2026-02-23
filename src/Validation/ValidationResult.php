<?php

namespace Native\Mobile\Validation;

class ValidationResult
{
    protected array $errors = [];

    protected array $warnings = [];

    public function error(string $file, string $message, ?int $line = null): void
    {
        $this->errors[] = [
            'file' => $file,
            'message' => $message,
            'line' => $line,
        ];
    }

    public function warning(string $file, string $message, ?int $line = null): void
    {
        $this->warnings[] = [
            'file' => $file,
            'message' => $message,
            'line' => $line,
        ];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }

    public function warningCount(): int
    {
        return count($this->warnings);
    }

    public function hasIssues(): bool
    {
        return $this->errorCount() > 0 || $this->warningCount() > 0;
    }

    public function passed(): bool
    {
        return ! $this->hasIssues();
    }

    /**
     * Get all issues (errors + warnings) grouped by file.
     *
     * @return array<string, array<array{type: string, message: string, line: ?int}>>
     */
    public function groupedByFile(): array
    {
        $grouped = [];

        foreach ($this->errors as $error) {
            $grouped[$error['file']][] = [
                'type' => 'error',
                'message' => $error['message'],
                'line' => $error['line'],
            ];
        }

        foreach ($this->warnings as $warning) {
            $grouped[$warning['file']][] = [
                'type' => 'warning',
                'message' => $warning['message'],
                'line' => $warning['line'],
            ];
        }

        return $grouped;
    }
}
