<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Events\Camera\VideoRecorded;

class PendingVideoRecorder
{
    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    public function __construct(
        protected array $options = []
    ) {
        // Default to the VideoRecorded event
        $this->eventClass = VideoRecorded::class;
    }

    /**
     * Set a unique identifier for this video recording to correlate events.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the video recorder's unique identifier (generates one if not set).
     * Use this to compare against the ID in VideoRecorded event listeners.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Set a custom event class to dispatch when video recording completes.
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
     * Store this video recorder's ID in the session for later retrieval in event handlers.
     * The ID will be flashed and available on the next request.
     */
    public function remember(): self
    {
        session()->flash('_native_video_recorder_id', $this->getId());

        return $this;
    }

    /**
     * Retrieve the last remembered video recorder ID from the session.
     * Useful for comparing against VideoRecorded event IDs in listeners.
     */
    public static function lastId(): ?string
    {
        return session('_native_video_recorder_id');
    }

    /**
     * Set the maximum recording duration in seconds.
     */
    public function maxDuration(int $seconds): self
    {
        $this->options['maxDuration'] = $seconds;

        return $this;
    }

    /**
     * Start the video recording.
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

            $result = nativephp_call('Camera.RecordVideo', json_encode($payload));

            if ($result) {
                $decoded = json_decode($result, true);

                return isset($decoded['status']) && $decoded['status'] === 'success';
            }
        }

        return false;
    }

    /**
     * Automatically start video recording if start() wasn't explicitly called.
     * This maintains backward compatibility with code that doesn't call start().
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->start();
        }
    }
}
