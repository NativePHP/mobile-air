<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Native\Mobile\Support\BinaryIntegrity;
use PHPUnit\Framework\TestCase;

/**
 * The manifest this line installs from has to be signed with a key it pins.
 *
 * `native:install` refuses an unsigned or wrongly signed manifest, so
 * releasing with a key the published `main/versions.json` isn't signed with
 * would break every install.
 */
final class ManifestSignatureSyncTest extends TestCase
{
    private const URL = 'https://bin.nativephp.com/main/versions.json';

    public function test_the_manifest_is_signed_with_a_pinned_key(): void
    {
        if (BinaryIntegrity::MANIFEST_KEYS === []) {
            $this->markTestSkipped('No manifest keys are pinned, so there is no signature to check.');
        }

        $manifest = $this->fetch(self::URL);
        $signature = $this->fetch(self::URL.'.sig');

        $this->assertTrue(
            BinaryIntegrity::signatureIsValid($manifest, $signature),
            sprintf(
                "%s isn't signed with any key in BinaryIntegrity::MANIFEST_KEYS, so every install of this "
                ."version would fail.\nRe-run php-bin-mobile's \"Build & Upload\" workflow with release 3.1.0 "
                .'to re-sign it with the current key, or fix the key listed here.',
                self::URL
            )
        );
    }

    private function fetch(string $url): string
    {
        try {
            return (new Client(['timeout' => 15]))->get($url)->getBody()->getContents();
        } catch (TransferException $e) {
            $this->cannotVerify(sprintf('Could not fetch %s: %s', $url, $e->getMessage()));
        }
    }

    /**
     * Offline shouldn't fail a local run. CI sets
     * NATIVEPHP_ENFORCE_BINARY_MANIFEST, which turns this into a failure.
     */
    private function cannotVerify(string $reason): never
    {
        if (getenv('NATIVEPHP_ENFORCE_BINARY_MANIFEST')) {
            $this->fail($reason);
        }

        $this->markTestSkipped(
            $reason."\nSet NATIVEPHP_ENFORCE_BINARY_MANIFEST=1 to treat this as a failure."
        );
    }
}
