<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Concerns\HandlesNativeCallbacks;
use Native\Mobile\Events\Alert\ButtonPressed;

/**
 * @method $this buttonPressed(\Closure|array|string $callback)
 */
class PendingAlert
{
    use HandlesNativeCallbacks;

    public const BUTTON_STYLES = ['default', 'cancel', 'destructive'];

    protected ?string $id = null;

    protected ?string $eventClass = ButtonPressed::class;

    protected bool $shown = false;

    public function __construct(
        protected string $title,
        protected string $message,
        protected array $buttons = []
    ) {
        $this->buttons = array_map(
            fn ($button) => $this->normalizeButton($button),
            array_values($buttons)
        );
    }

    /**
     * Buttons may be plain strings ("Delete") or arrays with a style:
     * ['label' => 'Delete', 'style' => 'destructive']. Strings stay strings
     * on the wire so older app shells keep working unchanged.
     */
    protected function normalizeButton(mixed $button): string|array
    {
        if (is_string($button)) {
            return $button;
        }

        if (! is_array($button) || ! is_string($button['label'] ?? null)) {
            throw new InvalidArgumentException(
                'Alert buttons must be strings or arrays with a string "label" key'
            );
        }

        $style = $button['style'] ?? 'default';

        if (! in_array($style, self::BUTTON_STYLES, true)) {
            throw new InvalidArgumentException(
                'Alert button style must be one of: '.implode(', ', self::BUTTON_STYLES)
            );
        }

        return ['label' => $button['label'], 'style' => $style];
    }

    /**
     * Set a unique identifier for this alert to correlate button press events.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the alert's unique identifier (generates one if not set).
     * Use this to compare against the ID in ButtonPressed event listeners.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Set a custom event class to dispatch when a button is pressed.
     */
    public function event(string $eventClass): self
    {
        if (! class_exists($eventClass)) {
            throw new InvalidArgumentException("Event class {$eventClass} does not exist");
        }

        $this->retargetPendingCallbacks($this->eventClass, $eventClass);

        $this->eventClass = $eventClass;

        return $this;
    }

    /**
     * Store this alert's ID in the session for later retrieval in event handlers.
     * The ID will be flashed and available on the next request.
     */
    public function remember(): self
    {
        session()->flash('_native_alert_id', $this->getId());

        return $this;
    }

    /**
     * Retrieve the last remembered alert ID from the session.
     * Useful for comparing against ButtonPressed event IDs in listeners.
     */
    public static function lastId(): ?string
    {
        return session('_native_alert_id');
    }

    /**
     * Display the alert.
     */
    public function show(): void
    {
        if ($this->shown) {
            return;
        }

        $this->shown = true;

        nativephp_call(
            'Dialog.Alert',
            json_encode([
                'title' => $this->title,
                'message' => $this->message,
                'buttons' => $this->buttons,
                'id' => $this->getId(),
                'event' => $this->eventClass,
            ])
        );
    }

    /**
     * Automatically display the alert if show() wasn't explicitly called.
     * This maintains backward compatibility with code that doesn't call show().
     */
    public function __destruct()
    {
        if (! $this->shown) {
            $this->show();
        }
    }
}
