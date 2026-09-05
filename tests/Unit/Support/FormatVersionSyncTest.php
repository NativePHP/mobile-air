<?php

namespace Tests\Unit\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Native\Mobile\Support\PhpBinaries;
use PHPUnit\Framework\TestCase;

/**
 * The wire-format contract between the binaries and the readers, enforced.
 *
 * One number lives in three places: `NPHP_FORMAT_VERSION` in the extension
 * (NativePHP/php-bin-mobile), `expectedFormatVersion` in the Swift reader and
 * `EXPECTED_FORMAT_VERSION` in the Kotlin one. The readers compare theirs
 * against the running extension on the first publish and refuse to render at
 * all on a mismatch, so a stale one ships an app that launches, runs, logs
 * nothing anyone reads, and never draws a thing.
 *
 * That failure is invisible until someone opens the app, which is much too
 * late — hence checking it here, where bumping {@see PhpBinaries::VERSION}
 * without moving the readers is a red build instead of a blank screen.
 *
 * The third value comes from the release itself: the manifest that names the
 * pinned release publishes the format its binaries speak as `format_version`.
 * Reading it from there rather than from a fourth constant kept in step by
 * hand means the check is against what was actually built and published, not
 * against what someone remembered to write down.
 */
final class FormatVersionSyncTest extends TestCase
{
    private const SWIFT_READER = 'resources/xcode/NativePHP/NativeRender/NativeElementBridge.swift';

    private const KOTLIN_READER = 'resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeElementBridge.kt';

    /** `private static let expectedFormatVersion: UInt32 = 4` */
    private const SWIFT_PATTERN = '/\bexpectedFormatVersion\s*:\s*UInt32\s*=\s*(\d+)/';

    /** `private const val EXPECTED_FORMAT_VERSION = 4` */
    private const KOTLIN_PATTERN = '/\bEXPECTED_FORMAT_VERSION\s*=\s*(\d+)/';

    public function test_the_two_readers_expect_the_same_format_version(): void
    {
        $this->assertSame(
            $this->kotlinFormatVersion(),
            $this->swiftFormatVersion(),
            'The Swift and Kotlin readers must expect the same wire format — they read the same '
            ."buffers written by the same extension.\n"
            .'Update both: '.self::SWIFT_READER.' and '.self::KOTLIN_READER
        );
    }

    public function test_the_readers_match_the_format_version_of_the_pinned_release(): void
    {
        $published = $this->publishedFormatVersion();

        $advice = sprintf(
            "\nEither the readers need to move to the format that release %s speaks, or "
            .'PhpBinaries::VERSION should point at a release that speaks theirs. Both halves '
            .'ship together or the app renders nothing.',
            PhpBinaries::VERSION
        );

        $this->assertSame(
            $published,
            $this->swiftFormatVersion(),
            'The Swift reader disagrees with the binaries release this package pins.'.$advice
        );

        $this->assertSame(
            $published,
            $this->kotlinFormatVersion(),
            'The Kotlin reader disagrees with the binaries release this package pins.'.$advice
        );
    }

    private function swiftFormatVersion(): int
    {
        return $this->readerConstant(self::SWIFT_READER, self::SWIFT_PATTERN);
    }

    private function kotlinFormatVersion(): int
    {
        return $this->readerConstant(self::KOTLIN_READER, self::KOTLIN_PATTERN);
    }

    /**
     * Pull a reader's expected format version out of its source.
     *
     * Insisting on exactly one match is the point: a renamed or duplicated
     * constant would otherwise leave this test quietly finding nothing and
     * passing, which is the same silence it exists to break.
     */
    private function readerConstant(string $relativePath, string $pattern): int
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        $this->assertFileExists(
            $path,
            "Reader source not found — if it moved, update this test's path: {$relativePath}"
        );

        $found = preg_match_all($pattern, (string) file_get_contents($path), $matches) ?: 0;

        $this->assertSame(1, $found, sprintf(
            'Expected exactly one format-version declaration in %s, found %d. '
            .'The constant was renamed, removed or duplicated — this check cannot see it any more.',
            $relativePath,
            $found
        ));

        return (int) $matches[1][0];
    }

    /**
     * The format version of the binaries release this package pins.
     */
    private function publishedFormatVersion(): int
    {
        $url = PhpBinaries::manifestUrl();
        $manifest = json_decode($this->fetchManifest($url), true);

        if (! is_array($manifest)) {
            $this->cannotVerify("The binaries manifest at {$url} is not valid JSON.");
        }

        if (! array_key_exists('format_version', $manifest)) {
            $this->cannotVerify(sprintf(
                "The manifest at %s carries no `format_version`.\n"
                .'Manifests published before php-bin-mobile started emitting the field simply '
                .'lack it. Re-run that repo\'s "Build & Upload" workflow (workflow_dispatch, '
                .'force_rebuild off — it skips the builds and just regenerates the manifest) to '
                .'republish %s with it.',
                $url,
                PhpBinaries::VERSION
            ));
        }

        if (! is_int($manifest['format_version'])) {
            $this->cannotVerify("`format_version` in {$url} should be a number.");
        }

        return $manifest['format_version'];
    }

    private function fetchManifest(string $url): string
    {
        try {
            return (new Client(['timeout' => 15]))->get($url)->getBody()->getContents();
        } catch (TransferException $e) {
            $this->cannotVerify(sprintf('Could not fetch %s: %s', $url, $e->getMessage()));
        }
    }

    /**
     * Something stopped the check from running — as opposed to the check
     * running and finding a mismatch, which is always a failure.
     *
     * Being offline shouldn't fail someone's local suite, so this skips by
     * default. A check that skips itself in CI guards nothing, though, so the
     * workflow sets NATIVEPHP_ENFORCE_BINARY_MANIFEST and "couldn't verify"
     * becomes a failure there.
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
