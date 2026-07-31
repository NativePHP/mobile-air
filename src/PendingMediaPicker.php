<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Events\Gallery\MediaSelected;

class PendingMediaPicker
{
    protected ?string $id = null;

    protected ?string $eventClass = null;

    protected bool $started = false;

    protected string $mediaType = 'all';

    protected bool $multiple = false;

    protected int $maxItems = 10;

    public function __construct(
        protected array $options = []
    ) {
        // Default to the MediaSelected event
        $this->eventClass = MediaSelected::class;
    }

    /**
     * Set a unique identifier for this media picker to correlate events.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the media picker's unique identifier (generates one if not set).
     * Use this to compare against the ID in MediaPicked event listeners.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Set a custom event class to dispatch when media picking completes.
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
     * Set the type of media to pick: "image", "video", or "all" (default).
     */
    public function mediaType(string $type): self
    {
        $this->mediaType = $type;

        return $this;
    }

    /**
     * Only allow image selection.
     */
    public function images(): self
    {
        $this->mediaType = 'image';

        return $this;
    }

    /**
     * Only allow video selection.
     */
    public function videos(): self
    {
        $this->mediaType = 'video';

        return $this;
    }

    /**
     * Allow both images and videos (default).
     */
    public function all(): self
    {
        $this->mediaType = 'all';

        return $this;
    }

    /**
     * Allow multiple media selection.
     */
    public function multiple(bool $multiple = true, int $maxItems = 10): self
    {
        $this->multiple = $multiple;
        $this->maxItems = $maxItems;

        return $this;
    }

    /**
     * Only allow single media selection (default).
     */
    public function single(): self
    {
        $this->multiple = false;

        return $this;
    }

    /**
     * Store this media picker's ID in the session for later retrieval in event handlers.
     * The ID will be flashed and available on the next request.
     */
    public function remember(): self
    {
        session()->flash('_native_media_picker_id', $this->getId());

        return $this;
    }

    /**
     * Retrieve the last remembered media picker ID from the session.
     * Useful for comparing against MediaPicked event IDs in listeners.
     */
    public static function lastId(): ?string
    {
        return session('_native_media_picker_id');
    }

    /**
     * Start the media picker.
     */
    public function start(): bool
    {
        if ($this->started) {
            return false;
        }

        $this->started = true;

        if (function_exists('nativephp_call')) {
            $payload = array_merge($this->options, [
                'mediaType' => $this->mediaType,
                'multiple' => $this->multiple,
                'maxItems' => $this->maxItems,
                'id' => $this->getId(),
                'event' => $this->eventClass,
            ]);

            $result = nativephp_call('Camera.PickMedia', json_encode($payload));

            if ($result) {
                $decoded = json_decode($result, true);

                return isset($decoded['status']) && $decoded['status'] === 'success';
            }
        }

        return false;
    }

    /**
     * Automatically start media picker if start() wasn't explicitly called.
     * This maintains backward compatibility with code that doesn't call start().
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->start();
        }
    }
}
