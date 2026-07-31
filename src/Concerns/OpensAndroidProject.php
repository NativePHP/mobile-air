<?php

namespace Native\Mobile\Concerns;

use Symfony\Component\Process\Process;

trait OpensAndroidProject
{
    public function openAndroidProject(): void
    {
        $projectPath = base_path('nativephp/android');

        if (! is_dir($projectPath)) {
            $this->error('Android project not found at /nativephp/android.');

            return;
        }

        try {
            if (PHP_OS_FAMILY === 'Darwin') {
                // Use full shell exec to make sure it behaves like your terminal
                $command = 'exec open -a "Android Studio" "'.$projectPath.'"';
                Process::fromShellCommandline($command)->run();
            } elseif (PHP_OS_FAMILY === 'Windows') {
                // 'start' on a bare directory opens Explorer, not the IDE — resolve Studio first
                $studio = $this->findWindowsStudioBinary();

                if ($studio !== null) {
                    $command = ['cmd', '/c', 'start', '', $studio, $projectPath];
                } else {
                    $this->warn('Android Studio not found; opening the project folder instead.');
                    $command = ['cmd', '/c', 'start', '', $projectPath];
                }

                // 'start' detaches and returns immediately, so run() won't block —
                // and Process::__destruct won't kill a still-running start()ed child
                (new Process($command))->run();
            } else {
                // Background via the shell so the IDE survives Process::__destruct's stop();
                // studio.sh runs in the foreground, so a plain run() would block until it closes
                $command = escapeshellarg($this->findStudioBinary()).' '.escapeshellarg($projectPath).' > /dev/null 2>&1 &';
                Process::fromShellCommandline($command)->run();
            }

            $this->info('Opening Android project...');
        } catch (\Throwable $e) {
            $this->error('Failed to open Android Studio: '.$e->getMessage());
        }
    }

    protected function findStudioBinary(): string
    {
        $whichStudio = exec('which studio');

        return ! empty($whichStudio) ? 'studio' : '/opt/android-studio/bin/studio.sh';
    }

    protected function findWindowsStudioBinary(): ?string
    {
        // Default installer locations (guard against getenv returning false)
        $candidates = [];

        if (($localAppData = getenv('LOCALAPPDATA')) !== false) {
            $candidates[] = $localAppData.'\\Programs\\Android Studio\\bin\\studio64.exe';
        }

        $candidates[] = (getenv('ProgramFiles') ?: 'C:\\Program Files').'\\Android\\Android Studio\\bin\\studio64.exe';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // JetBrains Toolbox drops studio.cmd shims on PATH
        foreach (['where studio 2>nul', 'where studio64 2>nul'] as $probe) {
            $output = [];
            exec($probe, $output);

            // 'where' may list multiple matches; the first has PATH precedence
            if (! empty($output[0])) {
                return trim($output[0]);
            }
        }

        return null;
    }
}
