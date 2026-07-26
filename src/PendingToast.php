<?php

namespace Native\Mobile;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PendingToast
{
    public const POSITION_TOP = 'top';

    public const POSITION_BOTTOM = 'bottom';

    /**
     * Seconds used for the legacy 'short' duration hint.
     */
    public const DURATION_SHORT = 2.0;

    /**
     * Seconds used for the legacy 'long' duration hint.
     */
    public const DURATION_LONG = 4.0;

    /**
     * The number of toasts that can be visible in the stack at once.
     * Toasts pushed while the stack is full are silently dropped.
     */
    public const MAX_STACK = 5;

    protected ?string $message = null;

    protected ?string $html = null;

    /**
     * Seconds the toast stays on screen once it reaches the front of the
     * stack. Null lets the platform pick a duration based on reading time,
     * zero keeps the toast up until it is dismissed.
     */
    protected ?float $duration = null;

    protected string $position = self::POSITION_BOTTOM;

    protected bool $dismissible = true;

    protected ?string $id = null;

    protected bool $shown = false;

    public function __construct(?string $message = null)
    {
        if ($message !== null) {
            $this->message($message);
        }
    }

    /**
     * Show a plain text toast using the platform's default styling.
     */
    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Render a view as the body of the toast, so its appearance is entirely
     * up to you. The view is rendered against the app's own base URL, so
     * relative asset paths (your compiled CSS, images, fonts) resolve
     * exactly as they do in the rest of your app.
     *
     * @param  View|Htmlable|string  $view  A view name, view instance or renderable
     * @param  array<string, mixed>  $data
     */
    public function view(View|Htmlable|string $view, array $data = []): self
    {
        $html = match (true) {
            $view instanceof View => $view->render(),
            $view instanceof Htmlable => $view->toHtml(),
            default => view($view, $data)->render(),
        };

        return $this->html($html);
    }

    /**
     * Use a raw HTML fragment (or full document) as the body of the toast.
     */
    public function html(string $html): self
    {
        $this->html = $this->document($html);

        return $this;
    }

    /**
     * How long the toast stays on screen once it reaches the front of the
     * stack: a number of seconds, or one of the 'short' / 'long' hints.
     * Pass null to let the platform choose based on reading time.
     */
    public function duration(int|float|string|null $duration): self
    {
        $this->duration = match (true) {
            $duration === null => null,
            $duration === 'short' => self::DURATION_SHORT,
            $duration === 'long' => self::DURATION_LONG,
            is_string($duration) && ! is_numeric($duration) => throw new InvalidArgumentException(
                "Unknown toast duration [{$duration}]. Use a number of seconds, 'short' or 'long'."
            ),
            (float) $duration < 0 => throw new InvalidArgumentException('Toast duration cannot be negative.'),
            default => (float) $duration,
        };

        return $this;
    }

    /**
     * Show the toast for the short duration (2 seconds).
     */
    public function short(): self
    {
        return $this->duration(self::DURATION_SHORT);
    }

    /**
     * Show the toast for the long duration (4 seconds).
     */
    public function long(): self
    {
        return $this->duration(self::DURATION_LONG);
    }

    /**
     * Keep the toast on screen until it is dismissed, either by the user or
     * by calling Toast::dismiss() with this toast's ID.
     */
    public function persistent(): self
    {
        return $this->duration(0);
    }

    /**
     * Where the toast appears: 'top' or 'bottom'.
     *
     * This is a hint: while a stack of toasts is on screen, new toasts join
     * the stack wherever it already lives so the stack never splits in two.
     */
    public function position(string $position): self
    {
        $position = strtolower($position);

        if (! in_array($position, [self::POSITION_TOP, self::POSITION_BOTTOM], true)) {
            throw new InvalidArgumentException(
                "Unknown toast position [{$position}]. Use 'top' or 'bottom'."
            );
        }

        $this->position = $position;

        return $this;
    }

    public function top(): self
    {
        return $this->position(self::POSITION_TOP);
    }

    public function bottom(): self
    {
        return $this->position(self::POSITION_BOTTOM);
    }

    /**
     * Whether the user can dismiss the toast by tapping or swiping it.
     */
    public function dismissible(bool $dismissible = true): self
    {
        $this->dismissible = $dismissible;

        return $this;
    }

    /**
     * Set a unique identifier for this toast so it can be dismissed later.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the toast's unique identifier (generates one if not set).
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * The payload sent to the native side.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->getId(),
            'message' => $this->message,
            'html' => $this->html,
            'duration' => $this->duration,
            'position' => $this->position,
            'dismissible' => $this->dismissible,
        ], fn ($value) => $value !== null);
    }

    /**
     * Display the toast.
     */
    public function show(): void
    {
        if ($this->shown) {
            return;
        }

        if ($this->message === null && $this->html === null) {
            throw new InvalidArgumentException('A toast needs a message or a view.');
        }

        $this->shown = true;

        if (! function_exists('nativephp_call')) {
            return;
        }

        if ($this->supportsStackedToasts()) {
            nativephp_call('Toast.Show', json_encode($this->toArray()));

            return;
        }

        // Platforms without the stacked toast bridge (Android, for now) still
        // get the plain text toast. A view-only toast has nothing to fall
        // back to there, so it's skipped rather than shown as raw markup.
        if ($this->message === null) {
            return;
        }

        nativephp_call('Dialog.Toast', json_encode([
            'message' => $this->message,
            'duration' => $this->duration !== null && $this->duration <= self::DURATION_SHORT ? 'short' : 'long',
        ]));
    }

    /**
     * Automatically display the toast if show() wasn't explicitly called.
     */
    public function __destruct()
    {
        // An empty toast is one that was never finished being built, rather
        // than one waiting to be shown — throwing here would be fatal.
        if ($this->shown || ($this->message === null && $this->html === null)) {
            return;
        }

        $this->show();
    }

    protected function supportsStackedToasts(): bool
    {
        // `Toast.*` is an iOS-only bridge today; Android has `Dialog.Toast`.
        // nativephp_can() cannot make that distinction under Jump, where it is
        // a stub that answers `true` for everything ("assume all functions are
        // available on the connected device"). Ask the platform instead —
        // Platform::current() probes the real device over the bridge, so it
        // gives an honest answer in both Jump and on-device.
        //
        // Deliberately only bailing on a KNOWN Android device: an unknown
        // platform (no bridge, tests) keeps the previous behaviour rather than
        // silently losing toasts somewhere new.
        if (Platform::isAndroid()) {
            return false;
        }

        // Still honour the capability check so an older iOS runtime without the
        // Toast.* functions falls back instead of dispatching into the void.
        return ! function_exists('nativephp_can') || nativephp_can('Toast.Show');
    }

    /**
     * Wrap a fragment in a minimal transparent document so it can be handed
     * to the platform's web view as-is. Full documents are left alone.
     */
    protected function document(string $content): string
    {
        if (Str::contains(Str::lower($content), '<html')) {
            return $content;
        }

        $styles = 'html,body{margin:0;padding:0;background:transparent;'
            .'-webkit-user-select:none;user-select:none;'
            .'-webkit-tap-highlight-color:transparent;}';

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            ."<style>{$styles}</style></head><body>{$content}</body></html>";
    }
}
