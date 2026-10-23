<?php

namespace Tests\Unit\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Native\Mobile\Support\PhpBinaries;
use PHPUnit\Framework\TestCase;

/**
 * Once this package pins a manifest key, the manifest for the release it
 * pins has to be signed with it.
 *
 * `native:install` refuses an unsigned or wrongly signed manifest when
 * {@see PhpBinaries::MANIFEST_KEYS} isn't empty. Releasing with a key that
 * the published manifest isn't signed with would break every install, so
 * this checks the real manifest the same way FormatVersionSyncTest does.
 */
final class ManifestSignatureSyncTest extends TestCase
{
    public function test_the_pinned_release_is_signed_with_a_pinned_key(): void
    {
        if (PhpBinaries::MANIFEST_KEYS === []) {
            $this->markTestSkipped('No manifest keys are pinned yet, so there is no signature to check.');
        }

        // Main's pinned release, whatever NATIVEPHP_BIN_BRANCH is set to here.
        $url = PhpBinaries::manifestUrl('main', PhpBinaries::VERSION);

        $manifest = $this->fetch($url);
        $signature = $this->fetch($url.'.sig');

        $this->assertTrue(
            PhpBinaries::signatureIsValid($manifest, $signature),
            sprintf(
                "%s isn't signed with any key in PhpBinaries::MANIFEST_KEYS, so every install of this "
                ."version would fail.\nRe-run php-bin-mobile's \"Build & Upload\" workflow (force_rebuild off) "
                .'to re-sign it with the current key, or fix the key listed here.',
                $url
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
     * NATIVEPHP_ENFORCE_BINARY_MANIFEST, which turns this into a failure, as
     * it does for FormatVersionSyncTest.
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
