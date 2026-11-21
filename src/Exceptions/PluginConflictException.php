<?php

namespace Native\Mobile\Exceptions;

use RuntimeException;

class PluginConflictException extends RuntimeException
{
    protected array $conflicts;

    public function __construct(array $conflicts)
    {
        $this->conflicts = $conflicts;

        $messages = [];
        foreach ($conflicts as $conflict) {
            $plugins = implode(' and ', $conflict['plugins']);
            $messages[] = match ($conflict['type']) {
                'namespace' => "Namespace '{$conflict['value']}' is used by both {$plugins}",
                'component' => "Component '{$conflict['value']}' is declared by both {$plugins}",
                'core_component' => "Component '{$conflict['value']}' is rendered by NativePHP core, so {$conflict['plugins'][1]} can't provide a renderer for it",
                default => "Bridge function '{$conflict['value']}' is registered by both {$plugins}",
            };
        }

        parent::__construct(
            "Plugin conflicts detected:\n".implode("\n", $messages).
            "\n\nUnregister one of the conflicting plugins with: php artisan native:plugin:register <plugin> --remove"
        );
    }

    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
