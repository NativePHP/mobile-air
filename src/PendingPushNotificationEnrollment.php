<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Events\PushNotification\TokenGenerated;

class PendingPushNotificationEnrollment
{
    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    public function __construct()
    {
        // Default to the TokenGenerated event
        $this->eventClass = TokenGenerated::class;
    }

    /**
     * Set a unique identifier for this enrollment to correlate events.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the enrollment's unique identifier (generates one if not set).
     * Use this to compare against the ID in TokenGenerated event listeners.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Set a custom event class to dispatch when the token is generated.
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
     * Store this enrollment's ID in the session for later retrieval in event handlers.
     * The ID will be flashed and available on the next request.
     */
    public function remember(): self
    {
        session()->flash('_native_push_enrollment_id', $this->getId());

        return $this;
    }

    /**
     * Retrieve the last remembered enrollment ID from the session.
     * Useful for comparing against TokenGenerated event IDs in listeners.
     */
    public static function lastId(): ?string
    {
        return session('_native_push_enrollment_id');
    }

    /**
     * Start the push notification enrollment process.
     * Requests permission and enrolls for push notifications.
     */
    public function enroll(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        if (function_exists('nativephp_call')) {
            nativephp_call(
                'PushNotification.RequestPermission',
                json_encode([
                    'id' => $this->getId(),
                    'event' => $this->eventClass,
                ])
            );
        }
    }

    /**
     * Automatically start enrollment if enroll() wasn't explicitly called.
     * This maintains backward compatibility and provides a fluent API.
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->enroll();
        }
    }
}
