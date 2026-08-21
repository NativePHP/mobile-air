<?php

namespace Native\Mobile\Concerns;

use Native\Mobile\Edge\NativeRouter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mobile's half of `native:watch`'s terminal.
 *
 * The terminal itself — the sticky footer, the key legend, the screen picker
 * behind `L` — is not mobile's, and moved to
 * [\SupaNative\Core\Concerns\InteractsWithWatchTerminal] when desktop grew a
 * watcher of its own. `native:watch ios` and `native:watch mac` are the same
 * command doing the same job and they should not look different in the terminal
 * for no better reason than having been written twice.
 *
 * What is left here is the part that genuinely is mobile's: which screens exist,
 * and the two actions the terminal can ask for — reload, and go to a screen —
 * each of which reaches the app over a wire that only the platform traits know
 * how to open. `$watchPlatform` is what picks between them: `WatchesIos` and
 * `WatchesAndroid` both compose this trait into the same command, and only one
 * of them is watching.
 */
trait InteractsWithWatchTerminal
{
    use \SupaNative\Core\Concerns\InteractsWithWatchTerminal;

    protected function watchTerminalOutput(): OutputInterface
    {
        return $this->output;
    }

    protected function watchTerminalIsInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * Stop the watcher, the Vite dev server and (on iOS) iproxy, then exit —
     * the same teardown Ctrl+C takes.
     */
    protected function stopWatching(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && method_exists($this, 'onPollingWatcherShutdown')) {
            $this->onPollingWatcherShutdown();
        }

        $this->onWatchmanShutdown();
    }

    /**
     * @return array<string, ?string>
     */
    protected function watchScreenRoutes(): array
    {
        return array_map(
            fn (array $entry) => $entry['class'] ?? null,
            NativeRouter::registeredRoutes(),
        );
    }

    protected function reloadWatchTarget(): void
    {
        match ($this->watchPlatform) {
            'ios' => $this->triggerIosReload(),
            'android' => $this->triggerAndroidReload(),
            default => null,
        };
    }

    protected function navigateWatchTargetTo(string $uri): bool
    {
        return match ($this->watchPlatform) {
            'ios' => $this->navigateIosTo($uri),
            'android' => $this->navigateAndroidTo($uri),
            default => false,
        };
    }

    /**
     * Write the screen-change intent to a temp file for the platform traits
     * to push. PHP on device consumes it on its way through the hot-reload
     * handler — see NativeRouter::takeScreenIntent().
     */
    protected function writeScreenIntentFile(string $uri): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nativephp-screen-');

        file_put_contents($path, json_encode([
            'uri' => '/'.ltrim($uri, '/'),
            'ts' => time(),
        ]));

        return $path;
    }
}
