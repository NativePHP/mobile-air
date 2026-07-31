<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Events\Microphone\MicrophoneRecorded;

class PendingMicrophone
{
    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    public function __construct()
    {
        // Default to the MicrophoneRecorded event
        $this->eventClass = MicrophoneRecorded::class;
    }

    /**
     * Set a unique identifier for this microphone recording to correlate events.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the microphone recorder's unique identifier (generates one if not set).
     * Use this to compare against the ID in MicrophoneRecorded event listeners.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Set a custom event class to dispatch when microphone recording completes.
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
     * Store this microphone recorder's ID in the session for later retrieval in event handlers.
     * The ID will be flashed and available on the next request.
     */
    public function remember(): self
    {
        session()->flash('_native_microphone_id', $this->getId());

        return $this;
    }

    /**
     * Retrieve the last remembered microphone recorder ID from the session.
     * Useful for comparing against MicrophoneRecorded event IDs in listeners.
     */
    public static function lastId(): ?string
    {
        return session('_native_microphone_id');
    }

    /**
     * Start the microphone recording.
     */
    public function start(): bool
    {
        if ($this->started) {
            return false;
        }

        $this->started = true;

        if (function_exists('nativephp_call')) {
            $payload = [
                'id' => $this->getId(),
                'event' => $this->eventClass,
            ];

            $result = nativephp_call('Microphone.Start', json_encode($payload));

            if ($result) {
                $decoded = json_decode($result, true);

                // After normalization, error responses have status="error"
                // Success responses return data only (empty map becomes {} or [])
                $isError = isset($decoded['status']) && $decoded['status'] === 'error';

                return ! $isError;
            }
        }

        return false;
    }

    /**
     * Automatically start microphone recording if start() wasn't explicitly called.
     * This maintains backward compatibility with code that doesn't call start().
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->start();
        }
    }
}
