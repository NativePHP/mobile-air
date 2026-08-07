<?php

namespace Native\Mobile;

/**
 * When a stored item may be decrypted.
 *
 * Passed to `SecureStorage::set()` per key, so an app can keep a refresh
 * token locked away while still letting a background sync read the access
 * token it needs. Every case maps to a `ThisDeviceOnly` iOS accessibility:
 * secrets written here never sync to iCloud Keychain and never restore onto
 * a different device.
 *
 * Android's EncryptedSharedPreferences master key is not tied to the device
 * lock state — stored values are readable whenever the app's process can
 * run, which is already after-first-unlock behaviour — so the plugin accepts
 * this and ignores it there.
 */
enum SecureStorageAccessibility: string
{
    /**
     * Readable only while the device is unlocked
     * (`kSecAttrAccessibleWhenUnlockedThisDeviceOnly`). The default, and the
     * safest — but a background read while the phone is locked returns
     * SecureStorageStatus::Unavailable rather than the value.
     */
    case WhenUnlocked = 'when_unlocked';

    /**
     * Readable after the device has been unlocked once since boot
     * (`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`). The right choice
     * for anything background execution has to read while the screen is
     * locked, e.g. an API access token driving a sync task.
     */
    case AfterFirstUnlock = 'after_first_unlock';

    /**
     * Readable only while unlocked, and only while a passcode is set
     * (`kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly`). The item is
     * discarded if the user removes their passcode.
     */
    case WhenPasscodeSet = 'when_passcode_set';
}
