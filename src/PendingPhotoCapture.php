<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Events\Camera\PhotoTaken;

class PendingPhotoCapture
{
    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    public function __construct(
        protected array $options = []
    ) {
        // Default to the PhotoTaken event
        $this->eventClass = PhotoTaken::class;
    }

    /**
     * Set a unique identifier for this photo capture to correlate events.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the photo capture's unique identifier (generates one if not set).
     * Use this to compare against the ID in PhotoTaken event listeners.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Set a custom event class to dispatch when photo capture completes.
     */
    public function event(string $eventClass): self
    {
        if (! class_exists($eventClass)) {
            throw new InvalidArgumentException("Event class {$eventClass} does not exist");
        }

        $this->eventClass = $eventClass;

        return $this;
    }

    /**
     * Store this photo capture's ID in the session for later retrieval in event handlers.
     * The ID will be flashed and available on the next request.
     */
    public function remember(): self
    {
        session()->flash('_native_photo_capture_id', $this->getId());

        return $this;
    }

    /**
     * Retrieve the last remembered photo capture ID from the session.
     * Useful for comparing against PhotoTaken event IDs in listeners.
     */
    public static function lastId(): ?string
    {
        return session('_native_photo_capture_id');
    }

    /**
     * Start the photo capture.
     */
    public function start(): bool
    {
        if ($this->started) {
            return false;
        }

        $this->started = true;

        if (function_exists('nativephp_call')) {
            $payload = array_merge($this->options, [
                'id' => $this->getId(),
                'event' => $this->eventClass,
            ]);

            $result = nativephp_call('Camera.GetPhoto', json_encode($payload));

            if ($result) {
                $decoded = json_decode($result, true);

                return isset($decoded['status']) && $decoded['status'] === 'success';
            }
        }

        return false;
    }

    /**
     * Automatically start photo capture if start() wasn't explicitly called.
     * This maintains backward compatibility with code that doesn't call start().
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->start();
        }
    }
}
