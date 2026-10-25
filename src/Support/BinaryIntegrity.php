<?php

namespace Native\Mobile\Support;

/**
 * Checks that tie the PHP binaries back to php-bin-mobile's build workflow.
 *
 * That workflow publishes a SHA-256 and size for every zip in the manifest,
 * under a `files` key next to the URL lists, and signs the manifest with
 * Ed25519 (`versions.json.sig`). The installers check downloads against the
 * checksums, and `native:install` checks the manifest against the signature
 * before reading it, so someone who can write to the bucket can't swap a zip
 * and its checksum together.
 */
class BinaryIntegrity
{
    /**
     * Public halves of the Ed25519 keys that sign the manifest, base64.
     *
     * The same key as nativephp/mobile 4.x pins. More than one is for
     * rotating: a new key has to be listed here before php-bin-mobile starts
     * signing with it.
     *
     * @var list<string>
     */
    public const MANIFEST_KEYS = [
        'fYf2iV1+S1cFJYqtMPwGqj2Cy/t1FKfWbIePpITSZks=',
    ];

    private const SIGNATURE_BYTES = 64;

    private const PUBLIC_KEY_BYTES = 32;

    /**
     * Whether $signature, base64 as published, signs $manifest with one of
     * $keys.
     *
     * Needs ext-sodium. Callers check for it first, because what to do
     * without it is their decision, not this one's.
     *
     * @param  list<string>  $keys
     */
    public static function signatureIsValid(string $manifest, string $signature, array $keys = self::MANIFEST_KEYS): bool
    {
        $raw = base64_decode(trim($signature), true);

        if ($raw === false || strlen($raw) !== self::SIGNATURE_BYTES) {
            return false;
        }

        foreach ($keys as $key) {
            $public = base64_decode($key, true);

            if ($public !== false && strlen($public) === self::PUBLIC_KEY_BYTES
                && sodium_crypto_sign_verify_detached($raw, $manifest, $public)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The checksum and size the manifest publishes for one zip.
     *
     * Null when it publishes none. Manifests from before php-bin-mobile added
     * them have no `files` key, and those installs carry on unchecked, as they
     * always have.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array{sha256: string, size: int}|null
     */
    public static function expectedFile(?array $manifest, string $minor, string $platform, string $url): ?array
    {
        $files = $manifest['versions'][$minor]['files'][$platform] ?? null;

        if (! is_array($files)) {
            return null;
        }

        foreach ($files as $file) {
            if (is_array($file) && ($file['url'] ?? null) === $url
                && is_string($file['sha256'] ?? null) && is_int($file['size'] ?? null)) {
                return ['sha256' => strtolower($file['sha256']), 'size' => $file['size']];
            }
        }

        return null;
    }

    /**
     * Why the file at $path isn't the one the manifest describes, or null when
     * it is.
     *
     * The size goes first because it's free, and a cut-off download is the
     * usual way this goes wrong.
     *
     * @param  array{sha256: string, size: int}  $expected
     */
    public static function fileMismatch(string $path, array $expected): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return 'it could not be read';
        }

        $size = filesize($path);

        if ($size !== $expected['size']) {
            return sprintf('it is %d bytes but the manifest says %d', $size, $expected['size']);
        }

        $hash = hash_file('sha256', $path);

        if (! is_string($hash)) {
            return 'it could not be read';
        }

        if (! hash_equals($expected['sha256'], $hash)) {
            return sprintf('its SHA-256 is %s but the manifest says %s', $hash, $expected['sha256']);
        }

        return null;
    }
}
