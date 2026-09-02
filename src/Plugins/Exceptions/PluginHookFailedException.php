<?php

namespace Native\Mobile\Plugins\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A plugin lifecycle hook did not succeed.
 *
 * Hooks stage the things a plugin needs to work at all, so a failed one
 * leaves an app that builds and installs but breaks on first use. The
 * build stops here rather than shipping that.
 */
class PluginHookFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $pluginName,
        public readonly string $hookName,
        public readonly int $exitCode = 1,
        ?Throwable $previous = null,
    ) {
        $reason = $previous
            ? $previous->getMessage()
            : "exit code {$exitCode}";

        parent::__construct(
            "Hook {$hookName} for {$pluginName} failed: {$reason}",
            0,
            $previous
        );
    }
}
