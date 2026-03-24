<?php

namespace Native\Mobile\Server;

use React\EventLoop\LoopInterface;

class FileWatcher
{
    private LoopInterface $loop;

    private array $watchPaths;

    private array $watchExtensions;

    private array $fileHashes = [];

    private $onChange;

    private $output;

    public function __construct(LoopInterface $loop, array $watchPaths, array $watchExtensions, callable $onChange, $output = null)
    {
        $this->loop = $loop;
        $this->watchPaths = $watchPaths;
        $this->watchExtensions = $watchExtensions;
        $this->onChange = $onChange;
        $this->output = $output;
    }

    public function start()
    {
        // Initial scan
        $this->scanFiles();

        // Check for changes every 500ms
        $this->loop->addPeriodicTimer(0.5, function () {
            $this->checkForChanges();
        });

        $this->log('File watcher started for: '.implode(', ', $this->watchPaths));
    }

    private function scanFiles()
    {
        foreach ($this->watchPaths as $path) {
            $fullPath = base_path($path);
            if (! is_dir($fullPath)) {
                continue;
            }

            $this->scanDirectory($fullPath);
        }
    }

    private function scanDirectory($dir)
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $dir.DIRECTORY_SEPARATOR.$file;

            if (is_dir($filePath)) {
                $this->scanDirectory($filePath);
            } elseif (is_file($filePath) && $this->shouldWatch($filePath)) {
                $this->fileHashes[$filePath] = md5_file($filePath);
            }
        }
    }

    private function shouldWatch($filePath)
    {
        foreach ($this->watchExtensions as $ext) {
            if (str_ends_with($filePath, '.'.$ext)) {
                return true;
            }
        }

        return false;
    }

    private function checkForChanges()
    {
        $changedFiles = [];

        foreach ($this->watchPaths as $path) {
            $fullPath = base_path($path);
            if (! is_dir($fullPath)) {
                continue;
            }

            $this->checkDirectory($fullPath, $changedFiles);
        }

        if (! empty($changedFiles)) {
            $this->log('Files changed: '.count($changedFiles));
            ($this->onChange)($changedFiles);
        }
    }

    private function checkDirectory($dir, &$changedFiles)
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $dir.DIRECTORY_SEPARATOR.$file;

            if (is_dir($filePath)) {
                $this->checkDirectory($filePath, $changedFiles);
            } elseif (is_file($filePath) && $this->shouldWatch($filePath)) {
                $currentHash = md5_file($filePath);

                if (! isset($this->fileHashes[$filePath])) {
                    // New file
                    $this->fileHashes[$filePath] = $currentHash;
                    $changedFiles[] = $filePath;
                } elseif ($this->fileHashes[$filePath] !== $currentHash) {
                    // Modified file
                    $this->fileHashes[$filePath] = $currentHash;
                    $changedFiles[] = $filePath;
                }
            }
        }
    }

    private function log($message)
    {
        if ($this->output) {
            $this->output->writeln("<info>[FileWatcher]</info> {$message}");
        }
    }
}
