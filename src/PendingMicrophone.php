<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Concerns\HandlesNativeCallbacks;
use Native\Mobile\Events\Microphone\MicrophoneCancelled;
use Native\Mobile\Events\Microphone\MicrophoneRecorded;

/**
 * @method $this microphoneRecorded(\Closure|array|string $callback)
 * @method $this microphoneCancelled(\Closure|array|string $callback)
 */
class PendingMicrophone
{
    use HandlesNativeCallbacks;

    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    protected string $format = 'm4a';

    protected int $sampleRate = 16000;

    protected float $maxDuration = 0;

    public function __construct()
    {
        // Default to the MicrophoneRecorded event
        $this->eventClass = MicrophoneRecorded::class;
    }

    /**
     * @return array<int, class-string>
     */
    protected function failureEvents(): array
    {
        return [MicrophoneCancelled::class];
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
     * Record mono 16-bit PCM WAV at the given sample rate instead of AAC (M4A), for a server that
     * takes uncompressed audio. MicrophoneRecorded then says "audio/wav".
     */
    public function wav(int $sampleRate = 16000): self
    {
        if ($sampleRate < 8000 || $sampleRate > 48000) {
            throw new InvalidArgumentException("A WAV sample rate must be between 8000 and 48000 Hz, not {$sampleRate}");
        }

        $this->format = 'wav';
        $this->sampleRate = $sampleRate;

        return $this;
    }

    /**
     * Stop recording by itself after this many seconds, as if stop() was called: MicrophoneRecorded
     * is dispatched with what was recorded.
     */
    public function maxDuration(float $seconds): self
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('A maximum duration must be more than 0 seconds');
        }

        $this->maxDuration = $seconds;

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

            if ($this->format === 'wav') {
                $payload['format'] = 'wav';
                $payload['sampleRate'] = $this->sampleRate;
            }

            if ($this->maxDuration > 0) {
                $payload['maxDuration'] = $this->maxDuration;
            }

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
