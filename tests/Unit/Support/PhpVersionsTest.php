<?php

namespace Tests\Unit\Support;

use Native\Mobile\Support\PhpVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which PHP binaries a host gets.
 */
class PhpVersionsTest extends TestCase
{
    public function test_8_6_is_supported(): void
    {
        $this->assertTrue(PhpVersions::isSupported('8.6'));
    }

    /**
     * forHost() walks the list looking for the first minor the host is at
     * least, so out of order it would hand a new host old binaries.
     */
    public function test_the_list_is_newest_first(): void
    {
        $sorted = PhpVersions::SUPPORTED;
        usort($sorted, fn (string $a, string $b) => version_compare($b, $a));

        $this->assertSame($sorted, PhpVersions::SUPPORTED);
    }

    #[DataProvider('supported')]
    public function test_a_supported_host_gets_its_own_minor(string $minor): void
    {
        $this->assertSame($minor, PhpVersions::forHost($minor));
    }

    public static function supported(): array
    {
        return array_combine(array_map(fn ($minor) => "host on {$minor}", PhpVersions::SUPPORTED), array_map(fn ($minor) => [$minor], PhpVersions::SUPPORTED));
    }

    public function test_a_host_newer_than_anything_supported_gets_the_newest(): void
    {
        $this->assertSame('8.6', PhpVersions::forHost('8.7'));
        $this->assertSame('8.6', PhpVersions::forHost('9.0'));
    }

    public function test_a_host_older_than_anything_supported_gets_the_oldest(): void
    {
        $this->assertSame('8.3', PhpVersions::forHost('8.2'));
        $this->assertSame('8.3', PhpVersions::forHost('7.4'));
    }

    public function test_minor_drops_the_patch_and_any_prerelease(): void
    {
        $this->assertSame('8.6', PhpVersions::minor('8.6.0'));
        $this->assertSame('8.6', PhpVersions::minor('8.6.0RC7'));
        $this->assertSame('8.6', PhpVersions::minor('8.6'));
    }

    public function test_host_is_the_running_php(): void
    {
        $this->assertSame(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, PhpVersions::host());
    }
}
