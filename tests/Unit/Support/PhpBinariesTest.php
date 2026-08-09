<?php

namespace Tests\Unit\Support;

use Native\Mobile\Support\PhpBinaries;
use PHPUnit\Framework\TestCase;

class PhpBinariesTest extends TestCase
{
    public function test_manifest_url_is_named_for_the_pinned_release(): void
    {
        $this->assertSame(
            'https://bin.nativephp.com/main/'.PhpBinaries::VERSION.'.json',
            PhpBinaries::manifestUrl()
        );
    }

    public function test_manifest_url_honours_the_branch(): void
    {
        $this->assertSame(
            'https://bin.nativephp.com/my-feature/'.PhpBinaries::VERSION.'.json',
            PhpBinaries::manifestUrl('my-feature')
        );
    }

    /**
     * The whole point of pinning: the URL must carry the version, so a given
     * release of this package can only ever resolve to the binaries it was
     * tested against. A floating name like `versions.json` would let a newer
     * publish reach an install that never opted into it.
     */
    public function test_manifest_url_is_not_a_floating_name(): void
    {
        $this->assertStringNotContainsString('versions.json', PhpBinaries::manifestUrl());
        $this->assertStringEndsWith('/'.PhpBinaries::VERSION.'.json', PhpBinaries::manifestUrl());
    }

    public function test_version_is_a_semver_string(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', PhpBinaries::VERSION);
    }
}
