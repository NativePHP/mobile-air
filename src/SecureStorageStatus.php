<?php

namespace Native\Mobile;

/**
 * The outcome of a secure storage read.
 *
 * Three of these carry a null value, and conflating them is what makes
 * `SecureStorage::get()` alone unsafe to branch on: "nothing is stored here"
 * and "the OS won't decrypt this right now" look identical to a caller that
 * only sees null. On iOS a Keychain item written with the default
 * `WhenUnlocked` accessibility is unreadable while the device is locked, so a
 * background job that treats null as "signed out" will happily wipe the very
 * tokens it should have waited for. `read()` returns the status alongside the
 * value so callers can retry instead of guessing.
 *
 * The string values are the wire vocabulary the native side answers with —
 * `error` is the shared bridge failure envelope every plugin already emits,
 * so it maps straight through.
 */
enum SecureStorageStatus: string
{
    /** The item exists and its value was decrypted. */
    case Found = 'found';

    /** The item genuinely isn't in the keychain/keystore. */
    case NotFound = 'not_found';

    /**
     * The item may well exist, but protected data is unavailable — iOS
     * refused to decrypt it because the device is locked. Transient: retry
     * once the device unlocks, or store the key with a looser accessibility
     * (see SecureStorageAccessibility::AfterFirstUnlock).
     */
    case Unavailable = 'unavailable';

    /**
     * The native call failed outright — the plugin isn't installed, the
     * keystore is corrupt, the bridge is unreachable. Inspect the result's
     * `code` and `message`.
     */
    case Failed = 'error';
}
