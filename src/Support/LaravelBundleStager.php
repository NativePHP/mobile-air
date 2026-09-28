<?php

namespace Native\Mobile\Support;

use Closure;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Concerns\CleansEnvFile;
use Native\Mobile\Exceptions\BundleStagingFailed;

/**
 * Builds the Laravel app tree that both platforms ship: the app source,
 * its Composer dependencies, an optimized autoloader, the non-runtime files
 * stripped out and a cleaned .env.
 *
 * Nothing in here depends on the platform. iOS and Android each used to do
 * this themselves, with slightly different flags, so building both paid for
 * the copy and the composer install twice. The only input that changes the
 * tree is whether dev dependencies are included, which follows the build
 * type (debug keeps them, release drops them), never the platform.
 *
 * Each platform then archives the staged tree in its own format without
 * modifying it (see BuildIosAppCommand::createAppZip() and
 * PreparesBuild::createZipBundle()), so one staged tree can feed both
 * builds at once.
 *
 * Every step is its own method so a step can be skipped when its inputs
 * have not changed since the last stage.
 */
class LaravelBundleStager
{
    use CleansEnvFile;

    /** @var Closure(string, callable): mixed */
    protected Closure $task;

    /** @var Closure(string): void */
    protected Closure $log;

    /**
     * @param  array<int, string>  $excludes  config('nativephp.cleanup_exclude_files')
     */
    public function __construct(
        protected string $source,
        protected string $path,
        protected bool $includeDevDependencies,
        protected array $excludes = [],
        ?Closure $task = null,
        ?Closure $log = null,
    ) {
        $this->task = $task ?? fn (string $title, callable $callback) => $callback();
        $this->log = $log ?? fn (string $message) => null;
    }

    /**
     * Stage the app into $path, replacing whatever was there.
     *
     * @throws BundleStagingFailed
     */
    public function stage(): string
    {
        $this->copySource();
        $this->installComposerDependencies();
        $this->optimizeAutoloader();
        $this->removeNonRuntimeFiles();
        $this->cleanEnv();

        return $this->path;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function includesDevDependencies(): bool
    {
        return $this->includeDevDependencies;
    }

    public function composerInstallCommand(): string
    {
        return $this->includeDevDependencies
            ? 'composer install --no-interaction'
            : 'composer install --no-dev --no-interaction';
    }

    protected function copySource(): void
    {
        ($this->log)("  Copying Laravel source: {$this->source} -> {$this->path}");

        // The copy puts BundleExclusions::REQUIRED_DIRECTORIES back on the way
        // out, so the tree composer install boots into already has them.
        ($this->task)('Copying Laravel app', fn () => BundleFileManager::copy(
            $this->source,
            $this->path,
            $this->excludes,
        ));
    }

    protected function installComposerDependencies(): void
    {
        $command = $this->composerInstallCommand();

        ($this->log)("  Running: {$command}");

        $this->runStep('Installing Composer dependencies', $command, 'Composer install failed. Check your dependencies and try again.');
    }

    protected function optimizeAutoloader(): void
    {
        $command = 'composer dump-autoload --optimize --classmap-authoritative';

        ($this->log)("  Running: {$command}");

        $this->runStep('Optimizing autoloader', $command, 'Autoloader optimization failed.');
    }

    protected function removeNonRuntimeFiles(): void
    {
        ($this->task)('Removing non-runtime files', function () {
            BundleFileManager::removeUnnecessaryFiles($this->path, $this->excludes);

            return true;
        });
    }

    /**
     * Strip secrets from the copied .env. Platform additions (ASSET_URL on
     * iOS) are layered on when archiving, so this file stays the same for
     * both platforms.
     */
    protected function cleanEnv(): void
    {
        $env = rtrim($this->path, '/').'/.env';

        if (! file_exists($env)) {
            return;
        }

        $this->cleanEnvFile($env);
    }

    protected function runStep(string $title, string $command, string $failure): void
    {
        $failed = null;

        ($this->task)($title, function () use ($command, &$failed) {
            $result = Process::path($this->path)
                ->forever()
                ->run($command);

            ($this->log)($result->output());

            if ($result->errorOutput()) {
                ($this->log)($result->errorOutput());
            }

            if (! $result->successful()) {
                $failed = $result->exitCode();

                return false;
            }

            return true;
        });

        if ($failed !== null) {
            ($this->log)("ERROR: {$command} failed with exit code {$failed}");

            throw new BundleStagingFailed($failure);
        }
    }
}
