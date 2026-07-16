<?php

namespace Native\Mobile\Plugins\IOS;

use DOMDocument;
use DOMElement;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

final readonly class ProvisioningProfile
{
    private function __construct(
        public string $uuid,
        public string $name,
        public string $contents,
    ) {}

    public static function fromData(Filesystem $files, string $contents, string $bundleId): self
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'nativephp-extension-profile-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create a temporary provisioning profile.');
        }

        try {
            $files->put($temporaryPath, $contents, true);
            chmod($temporaryPath, 0600);
            $result = Process::run(['security', 'cms', '-D', '-i', $temporaryPath]);
        } finally {
            $files->delete($temporaryPath);
        }

        if (! $result->successful()) {
            throw new InvalidArgumentException("Provisioning profile for {$bundleId} is not a valid mobileprovision file.");
        }

        $root = self::rootDictionary($result->output(), $bundleId);
        $uuid = self::stringValue($root, 'UUID');
        $name = self::stringValue($root, 'Name');
        $entitlements = self::dictionaryValue($root, 'Entitlements');
        $applicationIdentifier = $entitlements === null
            ? null
            : self::stringValue($entitlements, 'application-identifier');
        $prefix = self::firstArrayString($root, 'ApplicationIdentifierPrefix')
            ?? ($entitlements === null ? null : self::stringValue($entitlements, 'com.apple.developer.team-identifier'));

        if (! self::isUuid($uuid) || $name === null || trim($name) === ''
            || $applicationIdentifier === null || $prefix === null || trim($prefix, ". \t\n\r\0\x0B") === '') {
            throw new InvalidArgumentException("Provisioning profile for {$bundleId} is missing required metadata.");
        }

        $expectedApplicationIdentifier = rtrim($prefix, '.').'.'.$bundleId;
        if (! hash_equals($expectedApplicationIdentifier, $applicationIdentifier)) {
            throw new InvalidArgumentException("Provisioning profile application-identifier does not exactly match {$bundleId}.");
        }

        return new self($uuid, $name, $contents);
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{4}){3}-[A-Fa-f0-9]{12}$/D', $value) === 1;
    }

    private static function rootDictionary(string $xml, string $bundleId): DOMElement
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? self::firstElement($document->documentElement) : null;
        if ($root?->tagName !== 'dict') {
            throw new InvalidArgumentException("Provisioning profile for {$bundleId} contains an invalid property list.");
        }

        return $root;
    }

    private static function stringValue(DOMElement $dictionary, string $key): ?string
    {
        $value = self::value($dictionary, $key);

        return $value?->tagName === 'string' ? $value->textContent : null;
    }

    private static function dictionaryValue(DOMElement $dictionary, string $key): ?DOMElement
    {
        $value = self::value($dictionary, $key);

        return $value?->tagName === 'dict' ? $value : null;
    }

    private static function firstArrayString(DOMElement $dictionary, string $key): ?string
    {
        $array = self::value($dictionary, $key);
        $value = $array?->tagName === 'array' ? self::firstElement($array) : null;

        return $value?->tagName === 'string' ? $value->textContent : null;
    }

    private static function value(DOMElement $dictionary, string $key): ?DOMElement
    {
        foreach ($dictionary->childNodes as $child) {
            if (! $child instanceof DOMElement || $child->tagName !== 'key' || $child->textContent !== $key) {
                continue;
            }

            for ($value = $child->nextSibling; $value !== null; $value = $value->nextSibling) {
                if ($value instanceof DOMElement) {
                    return $value;
                }
            }
        }

        return null;
    }

    private static function firstElement(?DOMElement $parent): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }
}
