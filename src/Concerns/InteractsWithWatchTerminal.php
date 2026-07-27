<?php

namespace Native\Mobile\Concerns;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SearchPrompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Exceptions\WatchPromptCancelled;
use Native\Mobile\Support\Watch\WatchConsole;

/**
 * Drives the interactive side of `native:watch`: the sticky footer, the keys
 * it advertises, and the screen picker behind `L`.
 *
 * The platform traits own the wire (adb / devicectl / file copy); this trait
 * owns the terminal and dispatches to whichever platform is being watched.
 */
trait InteractsWithWatchTerminal
{
    protected ?WatchConsole $watchConsole = null;

    protected ?string $watchPlatform = null;

    protected ?string $watchDeviceLabel = null;

    /**
     * The screen the terminal last asked for. Null until the user changes
     * it — we can't ask the device where it currently is, so we only claim
     * to know once we've said so ourselves.
     */
    protected ?string $watchScreen = null;

    protected int $watchChangeCount = 0;

    /**
     * Key legend, in display order.
     */
    protected array $watchKeys = [
        'l' => 'change screen',
        'r' => 'reload',
        'c' => 'clear',
        'q' => 'quit',
    ];

    protected function startWatchConsole(string $platform, ?string $deviceLabel = null): void
    {
        $this->watchPlatform = $platform;
        $this->watchDeviceLabel = $deviceLabel;

        $this->watchConsole = new WatchConsole(
            $this->output,
            $this->input->isInteractive(),
        );

        $this->watchConsole->keys($this->watchKeys);
        $this->refreshWatchStatus();
        $this->watchConsole->start();
        $this->watchActivity('watching for changes');

        // Watching ends through the watchman / polling shutdown handlers,
        // which exit() and so cut later shutdown functions short. Register
        // first so the terminal is always handed back.
        register_shutdown_function(fn () => $this->stopWatchConsole());
    }

    /**
     * Hand the terminal back. Idempotent — the quit key, signal handlers and
     * shutdown functions all funnel through here and may overlap.
     */
    protected function stopWatchConsole(): void
    {
        $console = $this->watchConsole;
        $this->watchConsole = null;

        $console?->stop();
    }

    /**
     * Service the terminal between watcher polls: age the activity line and
     * run any keys the user pressed.
     */
    protected function pumpWatchTerminal(): void
    {
        if ($this->watchConsole === null) {
            return;
        }

        $this->watchConsole->tick();

        while (($key = $this->watchConsole->readKey()) !== null) {
            $this->handleWatchKey($key);

            if ($this->watchConsole === null) {
                return;
            }
        }
    }

    protected function handleWatchKey(string $key): void
    {
        match (strtolower($key)) {
            'l' => $this->changeWatchedScreen(),
            'r' => $this->reloadFromTerminal(),
            'c' => $this->watchConsole?->clearScrollback(),
            'q' => $this->quitWatching(),
            default => null,
        };
    }

    /**
     * Report a synced file. Deliberately transient — the running total is
     * the useful signal, not a scrolling history of every save.
     */
    protected function watchSynced(string $relativePath): void
    {
        $this->watchChangeCount++;

        $this->watchActivity(sprintf(
            'synced %s · %d %s',
            $relativePath,
            $this->watchChangeCount,
            $this->watchChangeCount === 1 ? 'change' : 'changes',
        ), 'green');
    }

    /**
     * Update the transient activity line. `$text` must be plain: the footer
     * truncates by visible length and styles the line as a whole.
     */
    protected function watchActivity(string $text, ?string $style = null): void
    {
        if ($this->watchConsole !== null) {
            $this->watchConsole->activity($text, $style);

            return;
        }

        $this->line($style !== null ? "<fg={$style}>{$text}</>" : $text);
    }

    /**
     * Write something worth keeping into the scrollback above the footer.
     * May contain style tags.
     */
    protected function watchNote(string $text): void
    {
        if ($this->watchConsole !== null) {
            $this->watchConsole->note($text);

            return;
        }

        $this->line($text);
    }

    private function refreshWatchStatus(): void
    {
        $this->watchConsole?->status([
            [null, $this->watchPlatform],
            [null, $this->watchDeviceLabel],
            ['screen', $this->watchScreen ?? 'app default'],
        ]);
    }

    private function reloadFromTerminal(): void
    {
        $this->watchActivity('reloading…', 'yellow');

        match ($this->watchPlatform) {
            'ios' => $this->triggerIosReload(),
            'android' => $this->triggerAndroidReload(),
            default => null,
        };
    }

    private function quitWatching(): void
    {
        $this->stopWatchConsole();

        // Same teardown Ctrl+C takes: stop the watcher, the Vite dev server
        // and (on iOS) iproxy, then exit.
        if (PHP_OS_FAMILY === 'Windows' && method_exists($this, 'onPollingWatcherShutdown')) {
            $this->onPollingWatcherShutdown();
        }

        $this->onWatchmanShutdown();
    }

    // ── Changing the active screen ──────────────────

    /**
     * The `L` key: pick a registered native route and make it the app's
     * active screen.
     */
    private function changeWatchedScreen(): void
    {
        $options = $this->nativeScreenOptions();

        if ($options === []) {
            $this->watchNote('<fg=yellow>No native screens registered.</> <fg=gray>Add a</> <fg=cyan>Route::native()</> <fg=gray>to your route files.</>');

            return;
        }

        if ($this->watchConsole === null || ! $this->watchConsole->isInteractive()) {
            $this->watchNote('<fg=yellow>Changing screens needs an interactive terminal.</>');

            return;
        }

        $this->watchConsole->suspend();

        // Ctrl+C belongs to the picker while the picker is open: backing out of
        // a screen you didn't mean to open shouldn't tear down the watcher.
        Prompt::cancelUsing(fn () => throw new WatchPromptCancelled);

        try {
            $uri = $this->fillScreenParameters($this->promptForNativeScreen($options));
        } catch (WatchPromptCancelled) {
            $uri = null;
        } finally {
            Prompt::cancelUsing(null);
            $this->watchConsole->resume();
        }

        if ($uri === null) {
            return;
        }

        $this->watchScreen = $uri;
        $this->refreshWatchStatus();

        if ($this->navigateWatchTargetTo($uri)) {
            $this->watchActivity("switching to {$uri}", 'cyan');
        } else {
            $this->watchNote("<fg=red>Could not switch to</> <fg=cyan>{$uri}</> <fg=gray>— is the app running on the device?</>");
        }
    }

    /**
     * Registered native routes as `uri pattern => label`, root first then
     * alphabetical, with the component name aligned alongside each URI.
     *
     * @return array<string, string>
     */
    protected function nativeScreenOptions(): array
    {
        $routes = NativeRouter::registeredRoutes();

        if ($routes === []) {
            return [];
        }

        uksort($routes, fn (string $a, string $b) => [$a !== '/', $a] <=> [$b !== '/', $b]);

        $pad = max(array_map(fn (string $uri) => mb_strlen($uri), array_keys($routes)));

        $options = [];

        foreach ($routes as $uri => $entry) {
            $component = class_basename($entry['class'] ?? '');
            $options[$uri] = trim(str_pad($uri, $pad + 3).$component);
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function promptForNativeScreen(array $options): ?string
    {
        // A handful of screens reads better as a list; a real app's worth of
        // them is much faster to filter than to scroll.
        if (count($options) <= 12) {
            return $this->cancelOnEscape(new SelectPrompt(
                label: 'Go to native screen',
                options: $options,
                scroll: 12,
                hint: 'Esc or Ctrl+C to stay put',
            ))->prompt();
        }

        return $this->cancelOnEscape(new SearchPrompt(
            label: 'Go to native screen',
            placeholder: 'Type to filter…',
            options: fn (string $value) => $value === ''
                ? $options
                : array_filter(
                    $options,
                    fn (string $label) => str_contains(strtolower($label), strtolower($value)),
                ),
            scroll: 12,
            hint: 'Arrow keys to pick a match, Esc to stay put',
        ))->prompt();
    }

    /**
     * Make Esc back out of a prompt, the way Ctrl+C already does.
     *
     * Prompts has no hook for this — Esc is simply an unhandled key — so the
     * throw comes from a `key` listener, which unwinds straight out of
     * `prompt()`. Safe to throw through: Prompts restores the tty in its
     * destructor, and WatchConsole re-applies the mode it captured at start()
     * rather than re-reading whatever state the terminal was left in.
     *
     * Compares against a bare Esc, so arrow keys (`\e[A` and friends, which
     * Prompts reads in one go) don't trip it.
     */
    private function cancelOnEscape(Prompt $prompt): Prompt
    {
        $prompt->on('key', function (string $key) {
            if ($key === Key::ESCAPE) {
                throw new WatchPromptCancelled;
            }
        });

        return $prompt;
    }

    /**
     * Fill in any `{param}` placeholders so the URI actually resolves
     * against the route registry.
     */
    private function fillScreenParameters(?string $pattern): ?string
    {
        if ($pattern === null) {
            return null;
        }

        if (! preg_match_all('/\{(\w+)\??\}/', $pattern, $matches)) {
            return $pattern;
        }

        $uri = $pattern;

        foreach ($matches[1] as $index => $name) {
            $value = $this->cancelOnEscape(new TextPrompt(
                label: "Value for {{$name}}",
                required: true,
                hint: 'Esc or Ctrl+C to stay put',
            ))->prompt();

            $uri = str_replace($matches[0][$index], rawurlencode(trim($value)), $uri);
        }

        return $uri;
    }

    private function navigateWatchTargetTo(string $uri): bool
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
