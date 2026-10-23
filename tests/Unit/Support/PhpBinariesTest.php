<?php

namespace Tests\Unit\Support;

use Native\Mobile\Support\PhpBinaries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhpBinariesTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setBranch(null);

        parent::tearDown();
    }

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

    /**
     * @return array<string, array{?string, string, ?string}>
     */
    public static function selections(): array
    {
        return [
            'unset' => [null, 'main', null],
            'empty' => ['', 'main', null],
            'main' => ['main', 'main', null],
            'a branch' => ['perf/android-opcache', 'perf/android-opcache', null],
            'a prerelease on main' => ['main/4.2.0-rc.1', 'main', '4.2.0-rc.1'],
            'a bare prerelease' => ['4.2.0-rc.1', 'main', '4.2.0-rc.1'],
            'a prerelease on a nested branch' => ['perf/android/4.2.0-beta.2', 'perf/android', '4.2.0-beta.2'],
            // Only a release with a prerelease suffix counts. Without one,
            // it's part of the branch name.
            'a version in a branch name' => ['feat/4.2.0', 'feat/4.2.0', null],
            'a version without a suffix' => ['main/4.2.0', 'main/4.2.0', null],
        ];
    }

    /**
     * A null release means the pinned VERSION.
     */
    #[DataProvider('selections')]
    public function test_the_branch_and_release_come_from_nativephp_bin_branch(?string $selection, string $branch, ?string $release): void
    {
        $this->setBranch($selection);

        $release ??= PhpBinaries::VERSION;

        $this->assertSame($branch, PhpBinaries::branch());
        $this->assertSame($release, PhpBinaries::release());
        $this->assertSame($release !== PhpBinaries::VERSION, PhpBinaries::isPrerelease());
        $this->assertSame(PhpBinaries::HOST.'/'.$branch.'/'.$release.'.json', PhpBinaries::manifestUrl());
    }

    public function test_a_prerelease_is_cached_apart_from_its_branch(): void
    {
        $this->setBranch('main/4.2.0-rc.1');
        $this->assertSame('main-4.2.0-rc.1', PhpBinaries::branchSegment());

        // Asking for it without the branch is the same prerelease, and the same cache.
        $this->setBranch('4.2.0-rc.1');
        $this->assertSame('main-4.2.0-rc.1', PhpBinaries::branchSegment());

        $this->setBranch(null);
        $this->assertSame('main', PhpBinaries::branchSegment());
    }

    public function test_a_branch_keeps_the_cache_directory_it_always_had(): void
    {
        $this->setBranch('perf/android-opcache');

        $this->assertSame('perf-android-opcache', PhpBinaries::branchSegment());
    }

    public function test_the_manifest_url_can_name_the_pinned_release_whatever_the_branch_says(): void
    {
        $this->setBranch('main/4.2.0-rc.1');

        $this->assertSame(
            PhpBinaries::HOST.'/main/'.PhpBinaries::VERSION.'.json',
            PhpBinaries::manifestUrl('main', PhpBinaries::VERSION)
        );
    }

    public function test_it_reads_the_format_version_the_readers_expect(): void
    {
        $kotlin = file_get_contents(dirname(__DIR__, 3)
            .'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeElementBridge.kt');

        preg_match('/\bEXPECTED_FORMAT_VERSION\s*=\s*(\d+)/', $kotlin, $matches);

        $this->assertSame((int) $matches[1], PhpBinaries::readerFormatVersion());
    }

    private function setBranch(?string $branch): void
    {
        if ($branch === null) {
            unset($_SERVER['NATIVEPHP_BIN_BRANCH'], $_ENV['NATIVEPHP_BIN_BRANCH']);
            putenv('NATIVEPHP_BIN_BRANCH');

            return;
        }

        $_SERVER['NATIVEPHP_BIN_BRANCH'] = $_ENV['NATIVEPHP_BIN_BRANCH'] = $branch;
        putenv("NATIVEPHP_BIN_BRANCH={$branch}");
    }
}
