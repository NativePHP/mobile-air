<?php

namespace Tests\Unit\Support;

use Native\Mobile\Support\PhpBinaries;
use PHPUnit\Framework\TestCase;

/**
 * The checks that tie a downloaded zip back to the manifest, and the manifest
 * back to php-bin-mobile's workflow.
 */
class PhpBinariesIntegrityTest extends TestCase
{
    private const URL = 'https://bin.nativephp.com/main/8.4/ios/ios-4.1.0-php8.4.27.zip';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'nativephp_integrity_');
        file_put_contents($this->file, 'the zip as it was built');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function test_it_finds_the_checksum_published_for_a_url(): void
    {
        $this->assertSame(
            ['sha256' => str_repeat('a', 64), 'size' => 123],
            PhpBinaries::expectedFile($this->manifest([
                ['url' => 'https://bin.nativephp.com/main/8.4/ios/ios-4.1.0-php8.4.27-icu.zip', 'sha256' => str_repeat('b', 64), 'size' => 456],
                ['url' => self::URL, 'sha256' => str_repeat('A', 64), 'size' => 123],
            ]), '8.4', 'ios', self::URL)
        );
    }

    /**
     * Every manifest published before checksums existed. Those installs
     * carry on as they always did.
     */
    public function test_a_manifest_without_checksums_publishes_none(): void
    {
        $manifest = ['versions' => ['8.4' => ['php_version' => '8.4.27', 'ios' => [self::URL]]]];

        $this->assertNull(PhpBinaries::expectedFile($manifest, '8.4', 'ios', self::URL));
        $this->assertNull(PhpBinaries::expectedFile(null, '8.4', 'ios', self::URL));
        $this->assertNull(PhpBinaries::expectedFile($this->manifest([]), '8.4', 'ios', self::URL));
    }

    public function test_an_entry_it_cannot_use_counts_as_no_checksum(): void
    {
        $this->assertNull(PhpBinaries::expectedFile($this->manifest([
            ['url' => self::URL, 'sha256' => str_repeat('a', 64)],
            ['url' => self::URL, 'size' => 123],
            ['url' => self::URL, 'sha256' => str_repeat('a', 64), 'size' => '123'],
            'https://bin.nativephp.com/not-an-object.zip',
        ]), '8.4', 'ios', self::URL));
    }

    public function test_the_file_the_manifest_describes_matches(): void
    {
        $this->assertNull(PhpBinaries::fileMismatch($this->file, $this->describe($this->file)));
    }

    public function test_a_cut_off_file_is_caught_by_its_size(): void
    {
        $expected = $this->describe($this->file);
        file_put_contents($this->file, 'the zip as it');

        $this->assertSame(
            sprintf('it is 13 bytes but the manifest says %d', $expected['size']),
            PhpBinaries::fileMismatch($this->file, $expected)
        );
    }

    public function test_a_changed_file_is_caught_by_its_hash(): void
    {
        $expected = $this->describe($this->file);
        file_put_contents($this->file, 'THE ZIP AS IT WAS BUILT');

        $this->assertStringStartsWith('its SHA-256 is ', PhpBinaries::fileMismatch($this->file, $expected));
    }

    public function test_a_missing_file_is_a_mismatch(): void
    {
        $expected = $this->describe($this->file);
        unlink($this->file);

        $this->assertSame('it could not be read', PhpBinaries::fileMismatch($this->file, $expected));
    }

    public function test_a_signature_by_a_listed_key_is_valid(): void
    {
        [$secret, $public] = $this->keypair();
        $manifest = '{"versions":{}}';

        $this->assertTrue(PhpBinaries::signatureIsValid($manifest, $this->sign($manifest, $secret), [$public]));
    }

    /**
     * Rotating means listing the new key next to the old one for a while.
     */
    public function test_any_listed_key_will_do(): void
    {
        [, $old] = $this->keypair();
        [$secret, $new] = $this->keypair();
        $manifest = '{"versions":{}}';

        $this->assertTrue(PhpBinaries::signatureIsValid($manifest, $this->sign($manifest, $secret), [$old, $new]));
    }

    public function test_a_signature_by_another_key_is_not_valid(): void
    {
        [$secret] = $this->keypair();
        [, $public] = $this->keypair();
        $manifest = '{"versions":{}}';

        $this->assertFalse(PhpBinaries::signatureIsValid($manifest, $this->sign($manifest, $secret), [$public]));
    }

    public function test_a_changed_manifest_fails_its_signature(): void
    {
        [$secret, $public] = $this->keypair();
        $signature = $this->sign('{"versions":{}}', $secret);

        $this->assertFalse(PhpBinaries::signatureIsValid('{"versions":{} }', $signature, [$public]));
    }

    public function test_signatures_and_keys_it_cannot_read_are_not_valid(): void
    {
        [$secret, $public] = $this->keypair();
        $manifest = '{"versions":{}}';
        $signature = $this->sign($manifest, $secret);

        $this->assertFalse(PhpBinaries::signatureIsValid($manifest, '', [$public]));
        $this->assertFalse(PhpBinaries::signatureIsValid($manifest, 'not base64!', [$public]));
        $this->assertFalse(PhpBinaries::signatureIsValid($manifest, base64_encode('short'), [$public]));
        $this->assertFalse(PhpBinaries::signatureIsValid($manifest, $signature, ['not a key']));
        $this->assertFalse(PhpBinaries::signatureIsValid($manifest, $signature, []));
    }

    /**
     * The signature file is uploaded as base64 text, and a trailing newline
     * from whatever wrote it shouldn't matter.
     */
    public function test_whitespace_around_the_signature_is_ignored(): void
    {
        [$secret, $public] = $this->keypair();
        $manifest = '{"versions":{}}';

        $this->assertTrue(PhpBinaries::signatureIsValid($manifest, $this->sign($manifest, $secret)."\n", [$public]));
    }

    /**
     * @param  list<mixed>  $files
     * @return array<string, mixed>
     */
    private function manifest(array $files): array
    {
        return ['versions' => ['8.4' => [
            'php_version' => '8.4.27',
            'ios' => [self::URL],
            'files' => ['ios' => $files],
        ]]];
    }

    /** @return array{sha256: string, size: int} */
    private function describe(string $path): array
    {
        return ['sha256' => hash_file('sha256', $path), 'size' => filesize($path)];
    }

    /** @return array{0: string, 1: string} The secret key, raw, and the public key, base64. */
    private function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [sodium_crypto_sign_secretkey($pair), base64_encode(sodium_crypto_sign_publickey($pair))];
    }

    private function sign(string $message, string $secret): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $secret));
    }
}
