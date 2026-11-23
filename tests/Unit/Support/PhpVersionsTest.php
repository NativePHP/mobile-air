<?php

namespace Tests\Unit\Support;

use Native\Mobile\Support\PhpVersions;
use PHPUnit\Framework\TestCase;

/**
 * Which PHP binaries a host gets, out of the ones a release publishes.
 */
class PhpVersionsTest extends TestCase
{
    private const RELEASE = ['8.3', '8.4', '8.5', '8.6'];

    public function test_available_lists_the_manifest_minors_newest_first(): void
    {
        $manifest = ['versions' => [
            '8.4' => ['php_version' => '8.4.28'],
            '8.6' => ['php_version' => '8.6.0'],
            '8.3' => ['php_version' => '8.3.37'],
            '8.10' => ['php_version' => '8.10.0'],
        ]];

        $this->assertSame(['8.10', '8.6', '8.4', '8.3'], PhpVersions::available($manifest));
    }

    public function test_available_is_empty_without_a_usable_manifest(): void
    {
        $this->assertSame([], PhpVersions::available(null));
        $this->assertSame([], PhpVersions::available([]));
        $this->assertSame([], PhpVersions::available(['versions' => 'nope']));
        $this->assertSame(['8.6'], PhpVersions::available(['versions' => ['8.6' => [], '8.7' => null]]));
    }

    public function test_a_host_gets_its_own_minor_when_the_release_has_it(): void
    {
        foreach (self::RELEASE as $minor) {
            $this->assertSame($minor, PhpVersions::forHost($minor, self::newestFirst(self::RELEASE)));
        }
    }

    /**
     * The point of reading the minors from the manifest: a release that
     * builds a new PHP needs no change here to reach a host running it.
     */
    public function test_a_minor_this_package_has_never_heard_of_is_used_once_published(): void
    {
        $this->assertSame('8.7', PhpVersions::forHost('8.7', ['8.7', '8.6', '8.5']));
        $this->assertSame('9.0', PhpVersions::forHost('9.0', ['9.0', '8.7']));
    }

    public function test_a_host_newer_than_the_release_gets_the_newest(): void
    {
        $this->assertSame('8.6', PhpVersions::forHost('8.7', self::newestFirst(self::RELEASE)));
        $this->assertSame('8.6', PhpVersions::forHost('9.0', self::newestFirst(self::RELEASE)));
    }

    public function test_a_host_older_than_the_release_gets_the_oldest(): void
    {
        $this->assertSame('8.3', PhpVersions::forHost('8.2', self::newestFirst(self::RELEASE)));
        $this->assertSame('8.3', PhpVersions::forHost('7.4', self::newestFirst(self::RELEASE)));
    }

    public function test_a_host_between_two_published_minors_gets_the_older_one(): void
    {
        $this->assertSame('8.3', PhpVersions::forHost('8.4', ['8.5', '8.3']));
    }

    public function test_with_nothing_available_the_host_minor_stands(): void
    {
        $this->assertSame('8.6', PhpVersions::forHost('8.6', []));
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

    /** @param  list<string>  $minors */
    private static function newestFirst(array $minors): array
    {
        return PhpVersions::available(['versions' => array_fill_keys($minors, [])]);
    }
}
