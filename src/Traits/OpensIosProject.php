<?php

namespace Native\Mobile\Traits;

use Symfony\Component\Process\Process;

trait OpensIosProject
{
    public function openIosProject(): void
    {
        $projectPath = base_path('nativephp/ios/NativePHP.xcworkspace');

        if (! is_dir($projectPath)) {
            $this->error('Xcode workspace not found at '.$projectPath);

            return;
        }

        try {
            if (PHP_OS_FAMILY === 'Darwin') {
                // Use full shell exec to make sure it behaves like your terminal
                $command = 'exec open -a "Xcode" "'.$projectPath.'"';
                Process::fromShellCommandline($command)->run();
            } else {
                $this->error('Opening Xcode projects is only supported on macOS.');

                return;
            }

            $this->info('Opening Xcode workspace...');
        } catch (\Throwable $e) {
            $this->error('Failed to open Xcode: '.$e->getMessage());
        }
    }
}
