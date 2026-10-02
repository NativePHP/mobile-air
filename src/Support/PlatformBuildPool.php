<?php

namespace Native\Mobile\Support;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

/**
 * Runs one build process per platform at the same time and waits for all
 * of them.
 *
 * Each platform's output goes to its own log file as it arrives, and each
 * complete line is handed to $onLine so the caller can echo it with a
 * prefix. The processes are polled in turn rather than waited on one after
 * the other, which would leave the other process blocked on a full pipe.
 */
class PlatformBuildPool
{
    /** @var array<string, array{command: array<int, string>, log: string}> */
    protected array $builds = [];

    /** @var array<string, InvokedProcess> */
    protected array $running = [];

    /** @var array<string, string> */
    protected array $buffers = [];

    /** @var array<string, float> */
    protected array $startedAt = [];

    /** @var array<string, float> */
    protected array $finishedAt = [];

    protected ?float $stopRequestedAt = null;

    /**
     * @param  Closure(string, string): void  $onLine  (platform, line)
     */
    public function __construct(
        protected string $workingDirectory,
        protected Closure $onLine,
        protected int $pollMicroseconds = 100_000,
        protected int $killAfterSeconds = 10,
    ) {}

    /**
     * @param  array<int, string>  $command
     */
    public function add(string $platform, array $command, string $logPath): self
    {
        $this->builds[$platform] = ['command' => $command, 'log' => $logPath];

        return $this;
    }

    /**
     * Start every build, wait for all of them, and return each one's exit code.
     *
     * @return array<string, PlatformBuildResult>
     */
    public function run(): array
    {
        foreach ($this->builds as $platform => $build) {
            file_put_contents($build['log'], '');

            $this->buffers[$platform] = '';
            $this->startedAt[$platform] = microtime(true);
            $this->running[$platform] = Process::path($this->workingDirectory)
                ->forever()
                ->start($build['command'], function (string $type, string $output) use ($platform) {
                    $this->receive($platform, $output);
                });
        }

        while ($this->anyRunning()) {
            $this->killIfStopIsTakingTooLong();

            usleep($this->pollMicroseconds);
        }

        $results = [];

        foreach ($this->running as $platform => $process) {
            $exitCode = $process->wait()->exitCode();

            $this->flush($platform);

            $results[$platform] = new PlatformBuildResult(
                platform: $platform,
                exitCode: $exitCode ?? 1,
                seconds: ($this->finishedAt[$platform] ?? microtime(true)) - $this->startedAt[$platform],
                logPath: $this->builds[$platform]['log'],
                interrupted: $this->stopRequestedAt !== null,
            );
        }

        return $results;
    }

    /**
     * Ask every build still running to stop. Anything that ignores SIGTERM
     * gets SIGKILL after $killAfterSeconds.
     */
    public function stop(): void
    {
        $this->stopRequestedAt ??= microtime(true);

        $this->signalRunning(defined('SIGTERM') ? SIGTERM : 15);
    }

    protected function anyRunning(): bool
    {
        $any = false;

        foreach ($this->running as $platform => $process) {
            if (isset($this->finishedAt[$platform])) {
                continue;
            }

            if ($process->running()) {
                $any = true;
            } else {
                $this->finishedAt[$platform] = microtime(true);
            }
        }

        return $any;
    }

    protected function killIfStopIsTakingTooLong(): void
    {
        if ($this->stopRequestedAt === null || microtime(true) - $this->stopRequestedAt < $this->killAfterSeconds) {
            return;
        }

        $this->signalRunning(defined('SIGKILL') ? SIGKILL : 9);
    }

    /**
     * Signal each running build and everything it started. The builds are
     * PHP processes that run xcodebuild or Gradle as their own children,
     * and signalling only the PHP process would leave those going.
     */
    protected function signalRunning(int $signal): void
    {
        foreach ($this->running as $platform => $process) {
            if (isset($this->finishedAt[$platform])) {
                continue;
            }

            try {
                $pid = $process->id();

                foreach ($pid ? $this->descendantsOf($pid) : [] as $child) {
                    $this->signalPid($child, $signal);
                }

                $process->signal($signal);
            } catch (\Throwable) {
                // It finished between the check and the signal.
            }
        }
    }

    /**
     * @return array<int, int>
     */
    protected function descendantsOf(int $pid): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [];
        }

        $children = array_filter(array_map('intval', explode("\n", (string) shell_exec('pgrep -P '.$pid.' 2>/dev/null'))));

        $all = [];

        foreach ($children as $child) {
            $all = [...$all, ...$this->descendantsOf($child), $child];
        }

        return $all;
    }

    protected function signalPid(int $pid, int $signal): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);

            return;
        }

        @shell_exec("kill -{$signal} {$pid} 2>/dev/null");
    }

    protected function receive(string $platform, string $output): void
    {
        file_put_contents($this->builds[$platform]['log'], $output, FILE_APPEND);

        $this->buffers[$platform] .= $output;

        while (($newline = strpos($this->buffers[$platform], "\n")) !== false) {
            $line = substr($this->buffers[$platform], 0, $newline);
            $this->buffers[$platform] = substr($this->buffers[$platform], $newline + 1);

            ($this->onLine)($platform, rtrim($line, "\r"));
        }
    }

    protected function flush(string $platform): void
    {
        if (($this->buffers[$platform] ?? '') !== '') {
            ($this->onLine)($platform, rtrim($this->buffers[$platform], "\r"));
            $this->buffers[$platform] = '';
        }
    }
}
