<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\PermissionRequestResult;
use Native\Mobile\Events\Geolocation\PermissionStatusReceived;

class PendingGeolocation
{
    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    protected string $action = 'getCurrentPosition';

    protected bool $fineAccuracy = false;

    public function __construct(string $action = 'getCurrentPosition')
    {
        $this->action = $action;

        // Set default event class based on action
        $this->eventClass = match ($action) {
            'getCurrentPosition' => LocationReceived::class,
            'checkPermissions' => PermissionStatusReceived::class,
            'requestPermissions' => PermissionRequestResult::class,
            default => LocationReceived::class,
        };
    }

    /**
     * Set a unique identifier for this geolocation request to correlate events.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the geolocation request's unique identifier (generates one if not set).
     * Use this to compare against the ID in event listeners.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Set a custom event class to dispatch when the geolocation operation completes.
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
     * Request high accuracy GPS location (vs lower accuracy network location).
     * Only applicable for getCurrentPosition.
     */
    public function fineAccuracy(bool $fine = true): self
    {
        $this->fineAccuracy = $fine;

        return $this;
    }

    /**
     * Store this geolocation request's ID in the session for later retrieval in event handlers.
     * The ID will be flashed and available on the next request.
     */
    public function remember(): self
    {
        session()->flash('_native_geolocation_id', $this->getId());

        return $this;
    }

    /**
     * Retrieve the last remembered geolocation request ID from the session.
     * Useful for comparing against event IDs in listeners.
     */
    public static function lastId(): ?string
    {
        return session('_native_geolocation_id');
    }

    /**
     * Execute the geolocation operation.
     * For backward compatibility, this is auto-called via __destruct if not explicitly called.
     */
    public function get(): bool
    {
        if ($this->started) {
            return false;
        }

        $this->started = true;

        if (function_exists('nativephp_call')) {
            $payload = match ($this->action) {
                'getCurrentPosition' => [
                    'id' => $this->getId(),
                    'event' => $this->eventClass,
                    'fineAccuracy' => $this->fineAccuracy,
                ],
                'checkPermissions', 'requestPermissions' => [
                    'id' => $this->getId(),
                    'event' => $this->eventClass,
                ],
                default => [
                    'id' => $this->getId(),
                    'event' => $this->eventClass,
                ],
            };

            $methodName = match ($this->action) {
                'getCurrentPosition' => 'Geolocation.GetCurrentPosition',
                'checkPermissions' => 'Geolocation.CheckPermissions',
                'requestPermissions' => 'Geolocation.RequestPermissions',
                default => 'Geolocation.GetCurrentPosition',
            };

            $result = nativephp_call($methodName, json_encode($payload));

            // The god method returns empty map for async operations
            // Success is determined by the event being dispatched
            return true;
        }

        return false;
    }

    /**
     * Automatically execute if get() wasn't explicitly called.
     * This maintains backward compatibility with code that doesn't call get().
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->get();
        }
    }
}
