<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\File;
use Native\Mobile\Support\InstalledBinaries;
use Native\Mobile\Support\PhpBinaries;
use Tests\TestCase;

class InstalledBinariesTest extends TestCase
{
    protected string $platformPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformPath = sys_get_temp_dir().'/nativephp_installed_binaries_test_'.uniqid();
        File::ensureDirectoryExists($this->platformPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->platformPath);
        $this->setBranch(null);

        parent::tearDown();
    }

    public function test_it_records_the_release_this_package_installs(): void
    {
        InstalledBinaries::record($this->platformPath);

        $this->assertSame(
            ['release' => PhpBinaries::VERSION, 'branch' => 'main'],
            InstalledBinaries::read($this->platformPath)
        );
        $this->assertSame(InstalledBinaries::expected(), InstalledBinaries::read($this->platformPath));
    }

    /**
     * A branch build and a main build publish the same release number, so
     * the release alone can't tell them apart.
     */
    public function test_it_records_the_branch_the_binaries_came_from(): void
    {
        $this->setBranch('feat/opcache');

        InstalledBinaries::record($this->platformPath);

        $this->assertSame(
            ['release' => PhpBinaries::VERSION, 'branch' => 'feat/opcache'],
            InstalledBinaries::read($this->platformPath)
        );

        $this->setBranch(null);

        $this->assertNotSame(InstalledBinaries::expected(), InstalledBinaries::read($this->platformPath));
    }

    /**
     * Installing a prerelease records the prerelease, so the next run
     * doesn't call it a mismatch. Going back to the pinned release does.
     */
    public function test_it_records_the_prerelease_nativephp_bin_branch_asks_for(): void
    {
        $this->setBranch('main/4.2.0-rc.1');

        InstalledBinaries::record($this->platformPath);

        $this->assertSame(
            ['release' => '4.2.0-rc.1', 'branch' => 'main'],
            InstalledBinaries::read($this->platformPath)
        );
        $this->assertSame(InstalledBinaries::expected(), InstalledBinaries::read($this->platformPath));

        $this->setBranch(null);

        $this->assertNotSame(InstalledBinaries::expected(), InstalledBinaries::read($this->platformPath));
    }

    public function test_nothing_is_recorded_for_a_project_that_was_never_installed(): void
    {
        $this->assertNull(InstalledBinaries::read($this->platformPath));
    }

    public function test_forget_removes_the_record(): void
    {
        InstalledBinaries::record($this->platformPath);

        InstalledBinaries::forget($this->platformPath);

        $this->assertFileDoesNotExist($this->platformPath.'/'.InstalledBinaries::FILE);
        $this->assertNull(InstalledBinaries::read($this->platformPath));
    }

    public function test_a_record_without_a_branch_is_from_main(): void
    {
        File::put($this->platformPath.'/'.InstalledBinaries::FILE, '{"release": "4.0.0"}');

        $this->assertSame(
            ['release' => '4.0.0', 'branch' => 'main'],
            InstalledBinaries::read($this->platformPath)
        );
    }

    public function test_a_record_it_cannot_read_counts_as_no_record(): void
    {
        $file = $this->platformPath.'/'.InstalledBinaries::FILE;

        foreach (['{"release": nope', '[]', '{"release": ""}', '{"release": 4}', '"4.1.0"'] as $contents) {
            File::put($file, $contents);

            $this->assertNull(InstalledBinaries::read($this->platformPath), "Read a release from: {$contents}");
        }
    }

    public function test_nothing_is_written_when_the_platform_project_does_not_exist(): void
    {
        $missing = $this->platformPath.'/missing';

        InstalledBinaries::record($missing);

        $this->assertDirectoryDoesNotExist($missing);
    }

    public function test_describe_names_the_branch_only_when_it_is_not_main(): void
    {
        $this->assertSame('4.1.0', InstalledBinaries::describe(['release' => '4.1.0', 'branch' => 'main']));
        $this->assertSame(
            '4.1.0 (feat/opcache branch)',
            InstalledBinaries::describe(['release' => '4.1.0', 'branch' => 'feat/opcache'])
        );
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
