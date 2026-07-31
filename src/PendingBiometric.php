<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Events\Biometric\Completed;

class PendingBiometric
{
    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    public function __construct()
    {
        $this->eventClass = Completed::class;
    }

    /**
     * Set a unique identifier for this biometric authentication to correlate events.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the biometric authentication's unique identifier (generates one if not set).
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Set a custom event class to dispatch when biometric authentication completes.
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
     * Store this biometric authentication's ID in the session for later retrieval.
     */
    public function remember(): self
    {
        session()->flash('_native_biometric_id', $this->getId());

        return $this;
    }

    /**
     * Retrieve the last remembered biometric authentication ID from the session.
     */
    public static function lastId(): ?string
    {
        return session('_native_biometric_id');
    }

    /**
     * Start the biometric authentication prompt.
     */
    public function prompt(): bool
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

            $result = nativephp_call('Biometric.Prompt', json_encode($payload));

            if ($result) {
                $decoded = json_decode($result, true);

                return isset($decoded['status']) && $decoded['status'] === 'success';
            }
        }

        return false;
    }

    /**
     * Automatically start biometric authentication if prompt() wasn't explicitly called.
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->prompt();
        }
    }
}
