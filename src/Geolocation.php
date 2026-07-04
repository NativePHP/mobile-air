<?php

namespace Native\Mobile;

class Geolocation
{
    /**
     * Get the current GPS location of the device.
     * Returns a PendingGeolocation instance for fluent API usage.
     *
     * Listen for the LocationReceived event to get the result.
     *
     * Example:
     *   Geolocation::getCurrentPosition()
     *       ->fineAccuracy()
     *       ->id('my-location-request')
     *       ->get();
     *
     * Backward compatible: If you don't chain methods, the request will
     * auto-trigger via __destruct.
     *
     * @param  bool  $fineAccuracy  Whether to use high accuracy mode (GPS vs network)
     */
    public function getCurrentPosition(bool $fineAccuracy = false): PendingGeolocation
    {
        return (new PendingGeolocation('getCurrentPosition'))
            ->fineAccuracy($fineAccuracy);
    }

    /**
     * Stream continuous location updates (the web watchPosition() equivalent).
     * Each native fix dispatches a LocationUpdated event correlated by the
     * watch id; attach a persistent handler with ->locationUpdated().
     *
     * Example:
     *   $this->watchId = Geolocation::watchPosition(fineAccuracy: true)
     *       ->minDistance(5)
     *       ->locationUpdated(fn ($e) => [$this->lat, $this->lng] = [$e->latitude, $e->longitude])
     *       ->getId();
     *
     * The watch stops when the component unmounts, or earlier via
     * Geolocation::clearWatch($watchId). Foreground-only.
     *
     * @param  bool  $fineAccuracy  Whether to use high accuracy mode (GPS vs network)
     */
    public function watchPosition(bool $fineAccuracy = false): PendingLocationWatch
    {
        return (new PendingLocationWatch)
            ->fineAccuracy($fineAccuracy);
    }

    /**
     * Stop a location watch started with watchPosition(). No-op for unknown ids.
     */
    public function clearWatch(string $id): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Geolocation.ClearWatch', json_encode(['id' => $id]));
        }
    }

    /**
     * Stop a background watch started with watchPosition()->background().
     *
     * The buffered fixes survive by default so a final drainWatch() can
     * collect the tail of the stream; pass $clearBuffer to delete them too.
     */
    public function stopBackgroundWatch(string $id, bool $clearBuffer = false): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Geolocation.StopBackgroundWatch', json_encode([
                'id' => $id,
                'clearBuffer' => $clearBuffer,
            ]));
        }
    }

    /**
     * Read fixes a background watch buffered natively while PHP wasn't
     * running (app backgrounded, process dead, device rebooted).
     *
     * The cursor is a byte offset into the append-only native buffer.
     * Persist the returned cursor (component property, session, cache) and
     * pass it back to read only what's new:
     *
     *   $result = Geolocation::drainWatch($this->watchId, $this->cursor);
     *   foreach ($result['fixes'] as $fix) { ... }   // latitude, longitude, accuracy, timestamp, ...
     *   $this->cursor = $result['cursor'];
     *
     * The buffer is the stream's source of truth — live LocationUpdated
     * events are best-effort real-time sugar while the app is foregrounded.
     *
     * @return array{fixes: array<int, array<string, mixed>>, cursor: int, size: int}
     */
    public function drainWatch(string $id, int $cursor = 0): array
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Geolocation.DrainWatchBuffer', json_encode([
                'id' => $id,
                'cursor' => $cursor,
            ]));

            if ($result) {
                $decoded = json_decode($result, true);

                if (is_array($decoded) && isset($decoded['fixes'])) {
                    return [
                        'fixes' => $decoded['fixes'],
                        'cursor' => (int) ($decoded['cursor'] ?? $cursor),
                        'size' => (int) ($decoded['size'] ?? 0),
                    ];
                }
            }
        }

        return ['fixes' => [], 'cursor' => $cursor, 'size' => 0];
    }

    /**
     * Discard buffered fixes BEFORE a byte offset — the reclaim half of
     * the drain → persist/upload → trim cycle:
     *
     *   $result = Geolocation::drainWatch($id, $cursor);
     *   // ... store $result['fixes'] in your DB / ship to your API ...
     *   Geolocation::trimWatch($id, $result['cursor']);
     *   $cursor = 0;   // offsets rebase after a trim
     *
     * Only trim what you have durably persisted — trimmed fixes are gone
     * from the device. Without trimming, the buffer grows until
     * stopBackgroundWatch(clearBuffer: true).
     */
    public function trimWatch(string $id, int $upTo): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Geolocation.TrimWatchBuffer', json_encode([
                'id' => $id,
                'upTo' => $upTo,
            ]));
        }
    }

    /**
     * The background watch that's currently persisted natively, or null.
     *
     * A freshly booted PHP runtime uses this to discover a watch that
     * outlived it (background stream, process death, reboot re-arm) and
     * re-attach:
     *
     *   if ($watch = Geolocation::backgroundWatchStatus()) {
     *       $this->watchId = $watch['id'];   // then drainWatch() / listen
     *   }
     *
     * @return array{id: string, event: string, minDistance: float, fineAccuracy: bool, bufferBytes: int, interval?: int}|null
     */
    public function backgroundWatchStatus(): ?array
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Geolocation.BackgroundWatchStatus', json_encode([]));

            if ($result) {
                $decoded = json_decode($result, true);

                if (is_array($decoded) && ($decoded['active'] ?? false)) {
                    unset($decoded['active']);

                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * Check current location permissions status.
     * Returns a PendingGeolocation instance for fluent API usage.
     *
     * Listen for the PermissionStatusReceived event to get the result.
     *
     * Example:
     *   Geolocation::checkPermissions()
     *       ->event(MyCustomEvent::class)
     *       ->get();
     */
    public function checkPermissions(): PendingGeolocation
    {
        return new PendingGeolocation('checkPermissions');
    }

    /**
     * Request location permissions from the user.
     * Returns a PendingGeolocation instance for fluent API usage.
     *
     * Listen for the PermissionRequestResult event to get the result.
     *
     * Example:
     *   Geolocation::requestPermissions()
     *       ->remember()
     *       ->get();
     */
    public function requestPermissions(): PendingGeolocation
    {
        return new PendingGeolocation('requestPermissions');
    }
}
