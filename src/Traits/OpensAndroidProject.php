<?php

namespace Native\Mobile\Traits;

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
                $command = ['cmd', '/c', 'start', '', $projectPath];
                (new Process($command))->start();
            } else {
                $command = [$this->findStudioBinary(), $projectPath];
                (new Process($command))->start();
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
}
