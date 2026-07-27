<?php

namespace Native\Mobile\Support\Watch;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * The terminal surface for `native:watch`.
 *
 * Two things live here: a sticky footer pinned below the scrollback (status
 * line, one transient activity line, key legend) and non-blocking single-key
 * input. The footer means file changes can update in place instead of
 * scrolling a history nobody reads, and the legend stays visible so the
 * available keys never have to be remembered.
 *
 * Everything degrades: without an interactive TTY (CI, piped output,
 * `--no-interaction`, Windows) the footer is not drawn and activity lines
 * print normally, one per change.
 */
class WatchConsole
{
    /**
     * Seconds of quiet before the activity line dims to "idle". Purely
     * cosmetic — the text stays, only its marker changes — so nothing is
     * lost if the user looks away.
     */
    private const IDLE_AFTER = 2.5;

    /**
     * Terminal mode as `stty -g` reported it before we switched to
     * character-at-a-time input; restored verbatim on stop().
     */
    private ?string $originalTtyMode = null;

    private bool $rawInput = false;

    /**
     * How many lines the footer currently occupies on screen. Drives the
     * relative cursor-up used to redraw it, so it must match reality
     * exactly — hence the hard truncation in line().
     */
    private int $drawn = 0;

    private bool $started = false;

    /** @var list<array{0: ?string, 1: string}> */
    private array $status = [];

    /** @var array<string, string> */
    private array $keys = [];

    private string $activity = '';

    private ?string $activityStyle = null;

    private float $activityAt = 0.0;

    private bool $idle = true;

    public function __construct(
        private OutputInterface $output,
        private bool $interactive = true,
    ) {}

    /**
     * True when the footer is live and keys are being read — i.e. when the
     * caller can rely on activity() replacing output rather than appending.
     */
    public function isInteractive(): bool
    {
        return $this->rawInput;
    }

    /**
     * Take over the terminal: switch to character-at-a-time input, hide the
     * cursor and draw the footer for the first time.
     */
    public function start(): void
    {
        $this->started = true;
        $this->rawInput = $this->enableRawInput();

        if ($this->decorated()) {
            $this->output->write("\033[?25l");
        }

        $this->draw();
    }

    /**
     * Give the terminal back — cooked input, visible cursor, no footer.
     * Idempotent: shutdown handlers, the quit key and signal paths all
     * funnel through here and may overlap.
     */
    public function stop(): void
    {
        if (! $this->started) {
            return;
        }

        $this->erase();

        if ($this->decorated()) {
            $this->output->write("\033[?25h");
        }

        $this->disableRawInput();
        $this->started = false;
    }

    /**
     * Step out of the way so something else can own the terminal (a
     * Laravel Prompts selector, say). Whatever it prints becomes ordinary
     * scrollback once resume() redraws the footer beneath it.
     */
    public function suspend(): void
    {
        $this->erase();

        if ($this->decorated()) {
            $this->output->write("\033[?25h");
        }

        $this->disableRawInput();
    }

    public function resume(): void
    {
        if (! $this->started) {
            return;
        }

        $this->rawInput = $this->enableRawInput();

        if ($this->decorated()) {
            $this->output->write("\033[?25l");
        }

        $this->draw();
    }

    /**
     * Set the footer's status segments. Each entry is a `[label, value]`
     * pair (a null label renders the value on its own); entries with an
     * empty value are dropped.
     *
     * @param  list<array{0: ?string, 1: ?string}>  $segments
     */
    public function status(array $segments): void
    {
        $this->status = array_values(array_filter(
            $segments,
            fn (array $segment) => ($segment[1] ?? '') !== '',
        ));

        $this->draw();
    }

    /**
     * Set the key legend, in display order: key => description.
     *
     * @param  array<string, string>  $keys
     */
    public function keys(array $keys): void
    {
        $this->keys = $keys;

        $this->draw();
    }

    /**
     * Replace the single activity line. `$text` must be plain — the footer
     * truncates by visible length, so it styles the whole line itself
     * rather than trying to measure embedded markup.
     */
    public function activity(string $text, ?string $style = null): void
    {
        $this->activity = $text;
        $this->activityStyle = $style;
        $this->activityAt = microtime(true);
        $this->idle = false;

        if (! $this->decorated()) {
            // No footer to update in place, so fall back to a plain line —
            // still one per change, which is what a log wants anyway.
            $this->output->writeln($style !== null ? "<fg={$style}>{$text}</>" : $text);

            return;
        }

        $this->draw();
    }

    /**
     * Write a line into the scrollback above the footer. For things worth
     * keeping — errors, screen changes — not for per-file churn.
     */
    public function note(string $text): void
    {
        $this->erase();
        $this->output->writeln($text);
        $this->draw();
    }

    /**
     * Wipe the scrollback above the footer.
     */
    public function clearScrollback(): void
    {
        if (! $this->decorated()) {
            return;
        }

        $this->erase();
        $this->output->write("\033[2J\033[H");
        $this->draw();
    }

    /**
     * Housekeeping for the caller's watch loop: ages the activity line out
     * to "idle". Cheap enough to call on every poll.
     */
    public function tick(): void
    {
        if ($this->idle || ! $this->decorated()) {
            return;
        }

        if ((microtime(true) - $this->activityAt) < self::IDLE_AFTER) {
            return;
        }

        $this->idle = true;
        $this->draw();
    }

    /**
     * The next buffered keypress, or null if nothing is waiting. Escape
     * sequences (arrow keys, function keys) are swallowed rather than
     * surfaced as the stray letters they end in.
     */
    public function readKey(): ?string
    {
        if (! $this->rawInput) {
            return null;
        }

        $read = [STDIN];
        $write = $except = [];

        if (@stream_select($read, $write, $except, 0, 0) < 1) {
            return null;
        }

        $char = @fread(STDIN, 1);

        if ($char === false || $char === '') {
            return null;
        }

        if ($char === "\033") {
            $this->drainEscapeSequence();

            return null;
        }

        return $char;
    }

    /**
     * Discard the rest of a CSI/SS3 sequence so its trailing letter (the
     * `A` of an up-arrow, say) is not mistaken for a command key.
     */
    private function drainEscapeSequence(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $read = [STDIN];
            $write = $except = [];

            if (@stream_select($read, $write, $except, 0, 1000) < 1) {
                return;
            }

            if (@fread(STDIN, 1) === '') {
                return;
            }
        }
    }

    // ── Footer rendering ────────────────────────────

    /**
     * Redraw the footer in place: back up over the previous draw, then
     * rewrite each line. Relative cursor movement (rather than absolute
     * positioning) is what makes this survive the terminal scrolling.
     */
    private function draw(): void
    {
        if (! $this->started || ! $this->decorated()) {
            return;
        }

        $lines = $this->footer();

        $buffer = $this->drawn > 0 ? "\033[{$this->drawn}A" : '';

        foreach ($lines as $line) {
            $buffer .= "\033[2K".$line."\n";
        }

        // A footer that just got shorter would otherwise leave its old
        // tail on screen.
        if ($this->drawn > count($lines)) {
            $buffer .= "\033[0J";
        }

        $this->output->write($buffer);
        $this->drawn = count($lines);
    }

    private function erase(): void
    {
        if ($this->drawn === 0 || ! $this->decorated()) {
            return;
        }

        $this->output->write("\033[{$this->drawn}A\033[0J");
        $this->drawn = 0;
    }

    /**
     * @return list<string>
     */
    private function footer(): array
    {
        return [
            $this->line([[str_repeat('─', $this->width()), 'gray']]),
            $this->line($this->statusSegments()),
            $this->line($this->activitySegments()),
            $this->line($this->legendSegments()),
        ];
    }

    /**
     * @return list<array{0: string, 1: ?string}>
     */
    private function statusSegments(): array
    {
        $segments = [[' ', null]];

        foreach ($this->status as $index => [$label, $value]) {
            if ($index > 0) {
                $segments[] = [' · ', 'gray'];
            }

            if ($label !== null) {
                $segments[] = [$label.' ', 'gray'];
            }

            $segments[] = [$value, 'default'];
        }

        return $segments;
    }

    /**
     * @return list<array{0: string, 1: ?string}>
     */
    private function activitySegments(): array
    {
        if ($this->activity === '') {
            return [[' ', null]];
        }

        return [
            [' ', null],
            [$this->idle ? '○ ' : '● ', $this->idle ? 'gray' : ($this->activityStyle ?? 'default')],
            [$this->activity, $this->idle ? 'gray' : ($this->activityStyle ?? 'default')],
        ];
    }

    /**
     * @return list<array{0: string, 1: ?string}>
     */
    private function legendSegments(): array
    {
        $segments = [[' ', null]];

        foreach ($this->keys as $key => $description) {
            if (count($segments) > 1) {
                $segments[] = ['  ', null];
            }

            $segments[] = [strtoupper($key), 'cyan'];
            $segments[] = [' '.$description, 'gray'];
        }

        return $segments;
    }

    /**
     * Compose one footer line from styled segments, truncated to the
     * terminal width. Truncation is not cosmetic: a wrapped line would
     * occupy two rows and throw off the cursor-up count on the next draw.
     *
     * @param  list<array{0: string, 1: ?string}>  $segments
     */
    private function line(array $segments): string
    {
        $budget = $this->width();
        $rendered = '';

        foreach ($segments as [$text, $style]) {
            if ($budget <= 0) {
                break;
            }

            if (mb_strlen($text) > $budget) {
                $text = $budget > 1 ? mb_substr($text, 0, $budget - 1).'…' : '…';
            }

            $budget -= mb_strlen($text);
            $text = $this->output->getFormatter()->escape($text);

            $rendered .= $style !== null ? "<fg={$style}>{$text}</>" : $text;
        }

        return $rendered;
    }

    private function width(): int
    {
        return max(20, (new Terminal)->getWidth() - 1);
    }

    private function decorated(): bool
    {
        return $this->output->isDecorated();
    }

    // ── Terminal input mode ─────────────────────────

    /**
     * Switch the tty to character-at-a-time, no-echo, fully non-blocking
     * reads. `isig` is deliberately left on so Ctrl+C keeps working
     * alongside the quit key.
     */
    private function enableRawInput(): bool
    {
        if (! $this->supportsRawInput()) {
            return false;
        }

        $mode = @shell_exec('stty -g 2>/dev/null');
        $mode = is_string($mode) ? trim($mode) : '';

        if ($mode === '') {
            return false;
        }

        $this->originalTtyMode = $mode;

        @shell_exec('stty -icanon -echo min 0 time 0 2>/dev/null');
        @stream_set_blocking(STDIN, false);

        return true;
    }

    private function disableRawInput(): void
    {
        if ($this->originalTtyMode === null) {
            return;
        }

        @shell_exec('stty '.escapeshellarg($this->originalTtyMode).' 2>/dev/null');

        // Restoring the tty mode is not enough — PHP's own non-blocking flag
        // on the stream outlives it, and anything that then reads STDIN
        // (a Laravel Prompts selector, say) gets a torrent of empty strings
        // instead of keys.
        @stream_set_blocking(STDIN, true);

        $this->originalTtyMode = null;
        $this->rawInput = false;
    }

    private function supportsRawInput(): bool
    {
        return $this->interactive
            && PHP_OS_FAMILY !== 'Windows'
            && $this->decorated()
            && function_exists('shell_exec')
            && defined('STDIN')
            && @stream_isatty(STDIN);
    }
}
