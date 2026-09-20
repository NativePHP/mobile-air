<?php

namespace Native\Mobile;

/**
 * The result of `SecureStorage::read()` — the value plus *why* there isn't
 * one, which a bare `?string` can't express.
 *
 *     $result = SecureStorage::read('access_token');
 *
 *     match (true) {
 *         $result->found()       => $this->sync($result->value),
 *         $result->missing()     => $this->requireSignIn(),
 *         $result->unavailable() => $this->retryAfterUnlock(),
 *         $result->failed()      => report($result->message),
 *     };
 */
final class SecureStorageResult
{
    public function __construct(
        public readonly SecureStorageStatus $status,
        public readonly ?string $value = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * Interpret a raw `SecureStorage.Get` response.
     *
     * Handles both the current vocabulary (an explicit `status`) and the
     * shape older builds of the secure storage plugin answer with, so a new
     * core paired with an old plugin still tells locked apart from empty.
     */
    public static function fromBridge(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return self::failure('BRIDGE_UNAVAILABLE', 'The native bridge returned no response.');
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return self::failure('MALFORMED_RESPONSE', "The native bridge returned a response that could not be decoded: {$json}");
        }

        $value = $decoded['value'] ?? null;
        $code = isset($decoded['code']) ? (string) $decoded['code'] : null;

        $status = SecureStorageStatus::tryFrom((string) ($decoded['status'] ?? ''));

        // Plugin builds that predate the status vocabulary answer a read with
        // nothing but {"value": …}, using the empty string for "nothing
        // stored". Infer from that. A locked read still surfaces there, as
        // Failed rather than Unavailable — the plugin raises the Keychain's
        // errSecInteractionNotAllowed through the bridge error envelope — and
        // Failed-vs-NotFound is the distinction callers actually branch on.
        $status ??= ($value === null || $value === '')
            ? SecureStorageStatus::NotFound
            : SecureStorageStatus::Found;

        // A native side that reports "protected data unavailable" through the
        // error envelope rather than a status still means the same thing.
        if ($status === SecureStorageStatus::Failed
            && in_array($code, ['UNAVAILABLE', 'PROTECTED_DATA_UNAVAILABLE'], true)) {
            $status = SecureStorageStatus::Unavailable;
        }

        return new self(
            $status,
            $status === SecureStorageStatus::Found && is_scalar($value) ? (string) $value : null,
            $code,
            isset($decoded['message']) ? (string) $decoded['message'] : null,
        );
    }

    public static function failure(string $code, string $message): self
    {
        return new self(SecureStorageStatus::Failed, code: $code, message: $message);
    }

    public function found(): bool
    {
        return $this->status === SecureStorageStatus::Found;
    }

    /** The key genuinely holds nothing — safe to treat as "never stored". */
    public function missing(): bool
    {
        return $this->status === SecureStorageStatus::NotFound;
    }

    /**
     * The device is locked and the OS won't decrypt the item. Transient —
     * back off and read again once the device unlocks, and never take this
     * as a signal to clear or re-provision the key.
     */
    public function unavailable(): bool
    {
        return $this->status === SecureStorageStatus::Unavailable;
    }

    public function failed(): bool
    {
        return $this->status === SecureStorageStatus::Failed;
    }

    /** The stored value, or $default for anything other than a hit. */
    public function valueOr(?string $default): ?string
    {
        return $this->found() ? $this->value : $default;
    }
}
