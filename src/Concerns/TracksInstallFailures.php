<?php

namespace Native\Mobile\Concerns;

use function Laravel\Prompts\error;

/**
 * Remembers that something went wrong, so the command can exit non-zero
 * instead of printing a success banner over a broken install.
 *
 * `native:install` reported success unconditionally. With the binaries host
 * unreachable it removed the existing project, created a new one, printed its
 * error, and still finished with "NativePHP for Mobile installed
 * successfully!" and exit code 0 — over an app with no PHP runtime in it.
 *
 * That was not only cosmetic. RunCommand already does
 * `$exitCode = $this->call('native:install', ['--force' => true])` and errors
 * when it is non-zero, so the check existed and could never fire: `native:run`
 * went on to build against the failed install.
 *
 * Failures are recorded by the same call that prints them, rather than by
 * separate bookkeeping, so the two cannot drift apart. If it was worth printing
 * as an error, it is worth failing for.
 */
trait TracksInstallFailures
{
    /** @var string[] */
    protected array $installFailures = [];

    protected function failInstall(string $message): void
    {
        $this->installFailures[] = $message;

        error($message);
    }

    protected function installFailed(): bool
    {
        return $this->installFailures !== [];
    }
}
