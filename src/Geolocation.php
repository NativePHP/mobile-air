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
