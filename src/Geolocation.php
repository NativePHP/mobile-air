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
