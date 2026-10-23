<?php

namespace Native\Mobile;

class SecureStorage
{
    /**
     * Store a secure value in the native keychain or keystore.
     *
     * @param  string  $key  The key to store the value under
     * @param  string|null  $value  The value to store securely (null deletes the key)
     * @param  SecureStorageAccessibility|null  $accessibility  When iOS may decrypt
     *                                                          this item; defaults to
     *                                                          WhenUnlocked. Re-setting
     *                                                          an existing key with a
     *                                                          different accessibility
     *                                                          migrates it in place.
     * @return bool True if successfully stored, false otherwise
     */
    public function set(string $key, ?string $value, ?SecureStorageAccessibility $accessibility = null): bool
    {
        if (function_exists('nativephp_call')) {
            $payload = [
                'key' => $key,
                'value' => $value,
            ];

            // Left out entirely when unset, so the native side can tell "use
            // the default" from "migrate this item" — re-setting a key without
            // naming an accessibility must not silently tighten one that was
            // deliberately stored as AfterFirstUnlock.
            if ($accessibility !== null) {
                $payload['accessibility'] = $accessibility->value;
            }

            $result = nativephp_call('SecureStorage.Set', json_encode($payload));

            if ($result) {
                $decoded = json_decode($result, true);

                return isset($decoded['success']) && $decoded['success'] === true;
            }
        }

        return false;
    }

    /**
     * Retrieve a secure value from the native keychain or keystore.
     *
     * Null covers every outcome that isn't a hit — including a device locked
     * too tightly to decrypt the item, which is *not* the same as the key
     * being absent. Anything that branches on the difference (background sync,
     * sign-out detection, re-provisioning) should call `read()` instead.
     *
     * @param  string  $key  The key to retrieve the value for
     * @return string|null The stored value, or null if it could not be read
     */
    public function get(string $key): ?string
    {
        return $this->read($key)->value;
    }

    /**
     * Retrieve a secure value along with the reason it isn't there.
     *
     * Distinguishes a hit from an empty key, a locked device, and a native
     * failure (a missing plugin, a corrupt keystore, an unreachable bridge).
     *
     * @param  string  $key  The key to retrieve the value for
     */
    public function read(string $key): SecureStorageResult
    {
        if (! function_exists('nativephp_call')) {
            return SecureStorageResult::failure(
                'BRIDGE_UNAVAILABLE',
                'Secure storage is only available inside a NativePHP app.'
            );
        }

        $payload = json_encode([
            'key' => $key,
        ]);

        return SecureStorageResult::fromBridge(
            nativephp_call('SecureStorage.Get', $payload)
        );
    }

    /**
     * Delete a secure value from the native keychain or keystore.
     *
     * @param  string  $key  The key to delete the value for
     * @return bool True if successfully deleted, false otherwise
     */
    public function delete(string $key): bool
    {
        if (function_exists('nativephp_call')) {
            $payload = json_encode([
                'key' => $key,
            ]);

            $result = nativephp_call('SecureStorage.Delete', $payload);

            if ($result) {
                $decoded = json_decode($result, true);

                return isset($decoded['success']) && $decoded['success'] === true;
            }
        }

        return false;
    }
}
